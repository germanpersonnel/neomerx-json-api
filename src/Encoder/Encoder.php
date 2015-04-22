<?php namespace Neomerx\JsonApi\Encoder;

/**
 * Copyright 2015 info@neomerx.com (www.neomerx.com)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use \Iterator;
use \Generator;
use \Neomerx\JsonApi\Contracts\Document\LinkInterface;
use \Neomerx\JsonApi\Contracts\Encoder\EncoderInterface;
use \Neomerx\JsonApi\Contracts\Document\ElementInterface;
use \Neomerx\JsonApi\Contracts\Document\FactoryInterface;
use \Neomerx\JsonApi\Contracts\Schema\ContainerInterface;
use \Neomerx\JsonApi\Contracts\Document\DocumentInterface;
use \Neomerx\JsonApi\Contracts\Schema\LinkObjectInterface;
use \Neomerx\JsonApi\Contracts\Schema\SchemaProviderInterface;
use \Neomerx\JsonApi\Contracts\Encoder\DocumentLinksInterface;
use \Neomerx\JsonApi\Contracts\Encoder\EncodingOptionsInterface;

/**
 * @package Neomerx\JsonApi
 */
class Encoder implements EncoderInterface
{
    /**
     * @var FactoryInterface
     */
    protected $factory;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var JsonEncodeOptions|null
     */
    protected $encodeOptions;

    /**
     * @var []
     */
    protected $linkStack;

    /**
     * @param FactoryInterface       $factory
     * @param ContainerInterface     $container
     * @param JsonEncodeOptions|null $encodeOptions
     */
    public function __construct(
        FactoryInterface $factory,
        ContainerInterface $container,
        JsonEncodeOptions $encodeOptions = null
    ) {
        $this->container     = $container;
        $this->factory       = $factory;
        $this->encodeOptions = $encodeOptions;
    }

    /**
     * @inheritdoc
     */
    public function encode(
        $data,
        DocumentLinksInterface $links = null,
        $meta = null,
        EncodingOptionsInterface $options = null
    ) {
        $this->linkStack = [];
        $docWriter       = $this->factory->createDocument();

        $meta  === null ?: $this->processMetaInfo($docWriter, $meta);
        $links === null ?: $this->processLinksInfo($docWriter, $links);

        $this->processData($docWriter, $data, $options);

        unset($this->linkStack);

        return $this->encodeToJson($docWriter->getDocument());
    }

    /**
     * Process meta information.
     *
     * @param DocumentInterface $document
     * @param array|object      $meta
     *
     * @return void
     */
    protected function processMetaInfo(DocumentInterface $document, $meta)
    {
        $document->setMetaToDocument($meta);
    }

    /**
     * Process links information.
     *
     * @param DocumentInterface      $document
     * @param DocumentLinksInterface $links
     *
     * @return void
     */
    protected function processLinksInfo(DocumentInterface $document, DocumentLinksInterface $links)
    {
        $links->getSelfUrl()  === null ?: $document->setSelfUrlToDocumentLinks($links->getSelfUrl());
        $links->getFirstUrl() === null ?: $document->setFirstUrlToDocumentLinks($links->getFirstUrl());
        $links->getLastUrl()  === null ?: $document->setLastUrlToDocumentLinks($links->getLastUrl());
        $links->getPrevUrl()  === null ?: $document->setPrevUrlToDocumentLinks($links->getPrevUrl());
        $links->getNextUrl()  === null ?: $document->setNextUrlToDocumentLinks($links->getNextUrl());
    }

    /**
     * Process data.
     *
     * @param DocumentInterface        $document
     * @param object|array             $data
     * @param EncodingOptionsInterface $options
     *
     * @return void
     */
    protected function processData(DocumentInterface $document, $data, EncodingOptionsInterface $options = null)
    {
        if ($data === null) {
            $document->setDataNull();
        } elseif (is_array($data) === true && empty($data) === true) {
            $document->setDataEmpty();
        } else {
            assert('is_object($data) === true || (is_array($data) === true && empty($data) === false)');

            is_array($data) === true ?
                $this->processDataAsArray($document, $data, $options) :
                $this->processDataAsObject($document, $data, $options);
        }
    }

    /**
     * Process data as array of objects.
     *
     * @param DocumentInterface        $document
     * @param array                    $data
     * @param EncodingOptionsInterface $options
     *
     * @return void
     */
    protected function processDataAsArray(
        DocumentInterface $document,
        array $data,
        EncodingOptionsInterface $options = null
    ) {
        assert('is_array($data) === true && empty($data) === false');

        $firstObject = $data[0];
        $objectClass = get_class($firstObject);
        $schema      = $this->container->getSchema($firstObject);

        foreach ($data as $resourceObject) {
            assert('$resourceObject instanceof '.$objectClass, 'All resource objects should have the same type.');
            $document->addToData(
                $this->convertObjectToElement($document, $resourceObject, $schema, $options)
            );
        }
    }

    /**
     * Process data as object.
     *
     * @param DocumentInterface        $document
     * @param object                   $data
     * @param EncodingOptionsInterface $options
     *
     * @return void
     */
    protected function processDataAsObject(DocumentInterface $document, $data, EncodingOptionsInterface $options = null)
    {
        assert('is_object($data) === true');
        $document->addToData(
            $this->convertObjectToElement($document, $data, $this->container->getSchema($data), $options)
        );
    }

    /**
     * Convert resource object to document element.
     *
     * @param DocumentInterface        $document
     * @param object                   $resource
     * @param SchemaProviderInterface  $schema
     * @param EncodingOptionsInterface $options
     *
     * @return ElementInterface
     */
    protected function convertObjectToElement(
        DocumentInterface $document,
        $resource,
        SchemaProviderInterface $schema,
        EncodingOptionsInterface $options = null
    ) {
        assert('is_object($resource) === true');

        $resourceSelfUrl = $schema->getSelfUrl($resource);

        return $this->factory->createElement(
            $schema->getResourceType(),
            $schema->getId($resource),
            $schema->getAttributes($resource),
            $resourceSelfUrl,
            $this->getLinkIterator($document, $resource, $resourceSelfUrl, $schema, $options),
            $schema->getMeta($resource)
        );
    }

    /**
     * Get links from resource.
     *
     * @param DocumentInterface        $document
     * @param object                   $resource
     * @param string                   $resourceSelfUrl
     * @param SchemaProviderInterface  $schema
     * @param EncodingOptionsInterface $options
     *
     * @return Generator LinkInterface[]
     */
    protected function getLinkIterator(
        DocumentInterface $document,
        $resource,
        $resourceSelfUrl,
        SchemaProviderInterface $schema,
        EncodingOptionsInterface $options = null
    ) {
        foreach ($schema->getLinkObjectIterator($resource) as $linkObject) {
            /** @var LinkObjectInterface $linkObject */
            array_push($this->linkStack, $linkObject->getName());
            try {
                if (count($this->linkStack) > $schema->getDefaultIncludeDepth()) {
                    continue;
                }

                // 1) Link as reference

                if ($linkObject->isShowAsReference() === true) {
                    yield $this->createShowAsReferenceLink($resourceSelfUrl, $linkObject);
                    continue;
                }

                // 2) Link as null, [] or link object (with 1 or many linkages)

                // TODO think if iteration through linked could be coupled with same iteration while including below

                /** @var SchemaProviderInterface $linkageSchema */
                list($link, $linkageSchema) = $this->createLink($resourceSelfUrl, $linkObject);
                yield $link;

                if ($linkObject->isShouldBeIncluded() === false) {
                    continue;
                }

                // 3) If we are here we add those linked objects to included

                $linkedData = $linkObject->getLinkedData();
                // if linked data is 1 object then iterate it but not its properties
                $linkedData = is_object($linkedData) ? [$linkedData] : $linkedData;
                foreach ($linkedData as $linkageObject) {
                    $includedIterator = $this->getLinkIterator(
                        $document,
                        $linkageObject,
                        $linkageSchema->getSelfUrl($linkageObject),
                        $linkageSchema,
                        $options
                    );

                    $document->addToIncluded(
                        $this->createElement($schema, $linkageSchema, $linkageObject, $includedIterator)
                    );
                }
            } finally {
                array_pop($this->linkStack);
            }
        }
    }

    /**
     * @param string              $parentResourceUrl
     * @param LinkObjectInterface $linkObject
     *
     * @return LinkInterface
     */
    protected function createShowAsReferenceLink($parentResourceUrl, LinkObjectInterface $linkObject)
    {
        assert('is_string($parentResourceUrl)');
        $relatedUrl = $parentResourceUrl . $linkObject->getRelatedSubUrl();
        return $this->factory->createLink($linkObject->getName(), true, null, [], null, $relatedUrl, null);
    }

    /**
     * @param string              $resourceSelfUrl
     * @param LinkObjectInterface $linkObject
     *
     * @return array
     */
    protected function createLink($resourceSelfUrl, LinkObjectInterface $linkObject)
    {
        $linkedData = $linkObject->getLinkedData();
        assert('is_null($linkedData) || is_object($linkedData) || is_array($linkedData)');

        if (empty($linkedData) === true) {
            $linkageSchema = null;
            $linkedDataIds = $linkedData === null ? null : [];
            $linkageType   = null;
        } else {
            $linkageSchema = $this->container->getSchema(
                is_array($linkedData) === true ? $linkedData[0] : $linkedData
            );
            $linkedDataIds = $linkageSchema->getIds($linkedData);
            $linkageType   = $linkageSchema->getResourceType();
        }

        $link = $this->factory->createLink(
            $linkObject->getName(),
            $linkObject->isShowAsReference(),
            $linkageType,
            $linkedDataIds,
            $linkObject->isShowSelf()    === false ? null : $resourceSelfUrl . $linkObject->getSelfSubUrl(),
            $linkObject->isShowRelated() === false ? null : $resourceSelfUrl . $linkObject->getRelatedSubUrl(),
            $linkObject->isShowMeta()    === false ? null : $linkageSchema->getMeta($linkObject->getLinkedData())
        );

        return [$link, $linkageSchema];
    }

    /**
     * @param SchemaProviderInterface $schema
     * @param SchemaProviderInterface $linkageSchema
     * @param object                  $linkageObject
     * @param Iterator                $includedIterator
     *
     * @return ElementInterface
     */
    protected function createElement(
        SchemaProviderInterface $schema,
        SchemaProviderInterface $linkageSchema,
        $linkageObject,
        Iterator $includedIterator
    ) {
        return $this->factory->createElement(
            $linkageSchema->getResourceType(),
            $linkageSchema->getId($linkageObject),
            $linkageSchema->getAttributes($linkageObject),
            $schema->isShowSelfInIncluded() === false ? null : $linkageSchema->getSelfUrl($linkageObject),
            $includedIterator,
            $schema->isShowMetaInIncluded() === false ? null : $linkageSchema->getMeta($linkageObject)
        );
    }

    /**
     * Encode array to JSON.
     *
     * @param array $document
     *
     * @return string
     */
    protected function encodeToJson(array $document)
    {
        return $this->encodeOptions === null ?
            json_encode($document) :
            json_encode($document, $this->encodeOptions->getOptions(), $this->encodeOptions->getDepth());
    }

    /**
     * Create encoder instance.
     *
     * @param array             $schemas Schema providers.
     * @param JsonEncodeOptions $encodeOptions
     *
     * @return Encoder
     */
    public static function instance(array $schemas, JsonEncodeOptions $encodeOptions = null)
    {
        assert('empty($schemas) === false', 'Schema providers should be specified.');

        /** @noinspection PhpUnnecessaryFullyQualifiedNameInspection */
        return new self(
            new \Neomerx\JsonApi\Document\Factory(),
            new \Neomerx\JsonApi\Schema\Container(
                new \Neomerx\JsonApi\Schema\Factory(),
                $schemas
            ),
            $encodeOptions
        );
    }
}
