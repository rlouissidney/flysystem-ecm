<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
declare(strict_types = 1);

namespace UJML3\Flysystem\Ecm;

use GuzzleHttp\Psr7\Stream as GuzzleStream;
use Psr\Http\Message\StreamInterface;
use GuzzleHttp\Psr7\StreamWrapper as GuzzleStreamWrapper;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\MimeTypeDetection\MimeTypeDetector;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Nuxeo\Client\Spi\NuxeoClientException;
use Nuxeo\Client\NuxeoClient;
use Nuxeo\Client\Objects\Document;
use Nuxeo\Client\Objects\Blob\Blob;

/**
 * Description of CMISAdapter
 *
 * @author r.louis-sidney
 */
class NuxeoAdapter implements FilesystemAdapter {

    /**
     * Key option for CMIS properties to use when creating new Folder or Document.
     * Expects: associative array of CMIS properties => values.
     * Default: [] (minimum required properties are handled internally).
     *
     * @var string
     */
    const OPTION_PROPERTIES = 'nuxeo_properties';

    /**
     * @var PathPrefix
     */
    private $prefix;

    /**
     * @var MimeTypeDetector
     */
    private $mimeTypeDetector;

    /**
     * Key option for auto creation of missing directories in path.
     * Expects: boolean true to create missing directories in path, false not to create missing directories in path
     * Default: true.
     *
     * @var string
     */
    const OPTION_AUTO_CREATE_DIRECTORIES = 'nuxeo_auto_create_directories';

    /**
     * A dkd/php-cmis session.
     *
     * @var Session
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param Session $session a dkd/php-cmis active session
     * @param string  $prefix  a prefix for all subsequent paths
     */
    public function __construct($prefix = null, $url = 'http://localhost:8080/nuxeo', $username = 'Administrator', $password = 'Administrator') {
        $this->client = new NuxeoClient($url, $username, $password);
        $this->client = $this->client->schemas("*");
        $this->prefix = $prefix == null ? '/' : $prefix . '/';
        $this->mimeTypeDetector = new FinfoMimeTypeDetector();
    }
    
     /**
     * Return a document 
     * @param type $path
     * @return type
     * @throws \RuntimeException
     */
    public function getDocument($path = '') {
        $doc = $this->fetchDocumentByPath($path);
        if ($doc != null) {
            return $doc;
        }
        throw new \RuntimeException('Could not fetch document path  ' . $path);
    }

    /**
     * 
     * @param type $documentPath
     * @return type
     */
    public function fetchDocumentByPath($documentPath) {
        try {
            $doc = $this->client->repository()->fetchDocumentByPath($documentPath, null, Document::class);
        } catch (\Exception $e) {
            return null;
        }
        return $doc;
    }

    /**
     * *
     * Attach blob
     * @param  $blob
     * @param  $document
     * @throws RuntimeException
     * @return blob
     */
    public function attachBlob($blob, $document) {
        try {
            if (null !== $document) {
                $retBlob = $this->client->automation('Blob.AttachOnDocument')
                        ->param('document', $document->getPath())
                        ->input($blob)
                        ->execute(Blob::class);
                return $retBlob;
            }
        } catch (NuxeoClientException $ex) {
            throw new \RuntimeException('Could not attach blob to document: ' . $ex->getMessage());
        }
        throw new \RuntimeException('Could not attach blob to document null ');
    }

    /**
     * Create a document
     * @param type $path
     * @param type $type
     * @param type $name
     * @param type $properties
     * @return type
     * @throws \RuntimeException
     */
    public function createDocument($path, $type, $name, $properties = null) {
        if ($properties == null) {
            $properties = array();
        }
        if (!array_key_exists('dc:title', $properties)) {
            $properties['dc:title'] = $name;
        }
        $document = Document::createWithName($name, $type, $this->client)->setProperties($properties);
        try {
            $document = $this->client->repository()->createDocumentByPath($path, $document, null, Document::class);
        } catch (NuxeoClientException $ex) {
            throw new \RuntimeException('Could not create Document ' . $ex->getMessage() . ' : ' . $name);
        }
        return $document;
    }

    /**
     * update Document properties
     * @param type $document
     * @param type $properties
     * @return type
     * @throws \RuntimeException
     */
    public function updateDocument($document, $properties) {
        try {
            if (!empty($properties)) {
                $document->setProperties($properties);
            }
            return $this->client->repository()->updateDocument($document, null, Document::class);
        } catch (NuxeoClientException $ex) {
            throw new \RuntimeException('Could not update Document ' . $ex->getMessage() . ' : ' . $document->getTitle());
        }
        return $document;
    }

    /**
     * Efface un document
     * @param string $path
     * @throws RuntimeException
     * @return boolean
     */
    public function deleteDocumentByPath($path) {
        try {
            $this->client->repository()->deleteDocumentByPath($path);
        } catch (NuxeoClientException $ex) {
            throw new \RuntimeException('Could not delete document ' . $path . ' : ' . $ex->getMessage());
        }
        return true;
    }

    /*     * *
     *
     * @param String $path
     * @return boolean
     */

    public function documentExist($path) {
        return $this->fetchDocumentByPath($path) != null;
    }

    /**
     * 
     * @param type $document
     * @param type $targetFolder
     * @param type $name
     * @return type
     * @throws \RuntimeException
     */
    public function copyDocument($document, $targetFolder, $name) {
        try {
            $doc = $this->client->automation('Document.Copy')
                    ->param('target', $targetFolder->getUid())
                    ->param('name', $name)
                    ->input('doc:' . $document->getUid())
                    ->execute(Document::class);
        } catch (NuxeoClientException $ex) {
            throw new \RuntimeException('Could not copy Document ' . $ex->getMessage() . ':' . $document->getTitle());
        }
        return $doc;
    }

    /**
     * 
     * @param type $document
     * @param type $targetFolder
     * @param type $name
     * @return type
     * @throws \RuntimeException
     */
    public function moveDocument($document, $targetFolder, $name) {
        try {
            $doc = $this->client->automation('Document.Move')
                    ->param('target', $targetFolder->getUid())
                    ->param('name', $name)
                    ->input('doc:' . $document->getUid())
                    ->execute(Document::class);
        } catch (NuxeoClientException $ex) {
            throw new \RuntimeException('Could not move Document  ' . $ex->getMessage() . ' : ' . $document->getTitle());
        }
        return $doc;
    }

    /**
     * 
     * @param type $document
     * @return type
     */
    public function fetchChildren($document) {
        return $this->client->repository()->fetchChildrenById($document->getUid());
    }

    /**
     * 
     * @param type $document
     * @return type
     * @throws RuntimeException
     */
    public function fetchBlob($document) {
        try {
            $props = $document->getProperties();
            $url = $props['file:content']['data'];
            $response = $this->client->get($url);
            $blob = Blob::fromHttpResponse($response);
        } catch (NuxeoClientException $ex) {
            throw new RuntimeException('Could not fetch blob ' . $ex->getMessage());
        }
        return $blob;
    }

    public function prefixPath(string $path): string {
        if ($path == null || $path == '') {
            return rtrim($this->prefix, '\\/');
        }
        return $this->prefix . ltrim($path, '\\/');
    }

    public function stripPrefix(string $path): string {
        /* @var string */
        return substr($path, strlen($this->prefix));
    }

    public function copy(string $source, string $destination, Config $config): void {
        $sourcePath = $this->prefixPath($source);
         try {
            $document = $this->getDocument($sourcePath);
        } catch (\RuntimeException $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
        $destinationPath = $this->prefixPath($destination);
        $targetPath = dirname($destinationPath);
        $name = basename($destinationPath);
        try {
            $document = $this->getDocument($destinationPath);
            $this->deleteDocumentByPath($document->getPath());
        } catch (\Exception $ex) {           
        }
        try {
            $this->ensureDirectory($targetPath, $config);
            $targetFolder = $this->getDocument($targetPath);
            $document = $this->getDocument($sourcePath);
            $this->copyDocument($document, $targetFolder, $name);
        } catch (\RuntimeException $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function createDirectory(string $path, Config $config): void {
        $location = $this->prefixPath($path);
        if ($this->documentExist($location)) {
            return;
        }
        $parentPath = dirname($location);
        $foldername = basename($location);
        $properties = $config->get(self::OPTION_PROPERTIES) ?: [];
        try {
            $this->ensureDirectory($parentPath, $config);
            $this->createFolder($parentPath, $foldername, $properties);
        } catch (\RuntimeException $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage());
        }
    }

    public function delete(string $path): void {
        $location = $this->prefixPath($path);
        try {
            $this->deleteDocumentByPath($location);
        } catch (\RuntimeException $e) {
            
        }
    }

    public function deleteDirectory(string $path): void {
        $location = $this->prefixPath($path);
        if ($this->documentExist($location)) {
            try {
                $this->deleteDocumentByPath($location);
            } catch (\RuntimeException $e) {
                throw UnableToDeleteDirectory::atLocation($location, $e->getMessage());
            }
        }
    }

    public function fileExists(string $path): bool {
        $location = $this->prefixPath($path);
        return $this->documentExist($location);
    }

    public function fileSize(string $path): FileAttributes {
        $location = $this->prefixPath($path);
        try {
            $document = $this->getDocument($location);
            $blob = $this->fetchBlob($document);
            if ($blob == null) {
                throw UnableToRetrieveMetadata::fileSize($path);
            }
            $fileSize = $blob->getStream()->getSize();
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage());
        }
        return new FileAttributes($path, $fileSize);
    }

    public function convertDate($date) {
        if (is_string($date)) {
            return \strtotime($date);
        }
        if ($date instanceof \DateTime) {
            return $date->getTimestamp();
        }
        return $date;
    }

    public function lastModified(string $path): FileAttributes {
        $location = $this->prefixPath($path);
        $lastModified = null;
        try {
            $document = $this->getDocument($location);
            $lastModified = $document->getLastModified();
        } catch (\RuntimeException $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage());
        }
        if ($lastModified == null) {
            throw UnableToRetrieveMetadata::lastModified($path, '');
        }
        $lastModified = $this->convertDate($lastModified);
        return new FileAttributes($path, null, null, $lastModified);
    }

    public function getObjectAttributes($object, $path) {
        $o_path = $this->stripPrefix($object->getPath());
        $lastModified = $this->convertDate($object->getLastModified());
        $isDirectory = $object->getType() == 'Folder' || $object->getType() == 'Workspace';
        if ($isDirectory == false) {
            try {
                $size = $this->fileSize($o_path)->fileSize();
            } catch (\Exception $e) {
                $size = null;
            }
        }
        $visibility = 'public';
        return $isDirectory ? new DirectoryAttributes($o_path, $visibility, $lastModified) : new FileAttributes(
                $o_path, $size, $visibility, $lastModified);
    }

    public function listContents(string $path, bool $deep): iterable {
        $iterator = $this->listDirContents($path, $deep);
        foreach ($iterator as $fileInfo) {
            yield $fileInfo;
        }
    }

    public function listDirContents(string $path, bool $deep): iterable {
        $location = $this->prefixPath($path);
        try {
            $object = $this->getDocument($location);
            $results = [];
            $childrenList = $this->fetchChildren($object);
            if ($childrenList != null) {
                foreach ($childrenList as $childObject) {
                    $result = $this->getObjectAttributes($childObject, $path);
                    $results[] = $result;
                    if ($deep && $childObject->getType() == 'Folder') {
                        $results = array_merge($results, $this->listDirContents($result->path(), true));
                    }
                }
            }
        } catch (\RuntimeException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
        return $results;
    }

    public function mimeType(string $path): FileAttributes {
        $location = $this->prefixPath($path);
        $mimeType = null;
        try {
            $document = $this->getDocument($location);
            $blob = $this->fetchBlob($document);
            if ($blob == null) {
                throw UnableToRetrieveMetadata::mimeType($path);
            }
            $mimeType = $blob->getMimeType();
        } catch (\RuntimeException $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage());
        }
        return new FileAttributes($path, null, null, null, $mimeType);
    }

    public function move(string $source, string $destination, Config $config): void {
        $sourcePath = $this->prefixPath($source);
        try {
            $document = $this->getDocument($sourcePath);
        } catch (\RuntimeException $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
        $destinationPath = $this->prefixPath($destination);
        $targetPath = dirname($destinationPath);
        $name = basename($destinationPath);
        $this->ensureDirectory($targetPath, $config);
        $targetFolder = $this->getDocument($targetPath);
        try {
            $document = $this->getDocument($destinationPath);
            $this->deleteDocumentByPath($document->getPath());
        } catch (\Exception $ex) {    
        }
        try {
            $this->moveDocument($document, $targetFolder, $name);
        } catch (\RuntimeException $e) {
            throw UnableToMoveFile::fromLocationTo($sourcePath, $destinationPath, $e);
        }
    }

    public function read(string $path): string {
        $location = $this->prefixPath($path);
        try {
            $document = $this->getDocument($location);
            $blob = $this->fetchBlob($document);
            if (null === $blob) {
                throw UnableToReadFile::fromLocation($path, '');
            }
            $contentStream = $blob->getStream();
            if (null === $contentStream) {
                throw UnableToReadFile::fromLocation($path, '');
            }
            return $contentStream->getContents();
        } catch (\RuntimeException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
        return null;
    }

    public function readStream(string $path) {
        $location = $this->prefixPath($path);
        try {
            $document = $this->getDocument($location);
            $blob = $this->fetchBlob($document);
            if (null === $blob) {
                throw UnableToReadFile::fromLocation($path, '');
            }
            $contentStream = $blob->getStream();
            if (null === $contentStream) {
                throw UnableToReadFile::fromLocation($path, '');
            }
            $resource = GuzzleStreamWrapper::getResource($contentStream);
            return $resource;
        } catch (\RuntimeException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
        return null;
    }

    public function setVisibility(string $path, string $visibility): void {
        throw UnableToSetVisibility::atLocation($path, $visibility);
    }

    public function visibility(string $path): FileAttributes {
        $location = $this->prefixPath($path);
        try {
            $object = $this->getDocument($location);
        } catch (\RuntimeException $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage());
        }
        $visibility = 'public';
        return new FileAttributes($path, null, $visibility);
    }

    public function write(string $path, string $contents, Config $config): void {
        $this->writeStream($path, $contents, $config);
    }

    public function writeStream($path, $contents, Config $config): void {
        $properties = $config->get(self::OPTION_PROPERTIES) ?: [];
        $mimeType = $this->mimeTypeDetector->detectMimeType($path, $contents);
        $location = $this->prefixPath($path);
        $parentPath = dirname($location);
        $filename = basename($location);

        // Une copie écrase le document
        try {
            $document = $this->getDocument($location);
            if ($document->getType() == 'File') {
                $stream = $this->streamFactory($contents);
                $blob = new \Nuxeo\Client\Objects\Blob\Blob($filename, $stream, $mimeType);
                $this->attachBlob($blob, $document);
                if (!empty($properties)) {
                    $this->updateDocument($document, $properties);
                }
                return;
            }
        } catch (\RuntimeException $e) {
            
        }
        try {
            $this->ensureDirectory($parentPath, $config);
            $stream = $this->streamFactory($contents);
            $blob = new \Nuxeo\Client\Objects\Blob\Blob($filename, $stream, $mimeType);
            $document = $this->createDocument($parentPath, 'File', $filename, $properties);
            $this->attachBlob($blob, $document);
        } catch (\RuntimeException $e) {
            throw UnableToWriteFile::atLocation($location, $e->getMessage());
        }
    }

    public function getClient() {
        return $this->client;
    }

    protected function ensureDirectory($path, Config $config) {
        if (false === $config->get(self::OPTION_AUTO_CREATE_DIRECTORIES)) {
            return;
        }
        $f_path = rtrim($this->prefixPath(''), '\\/');
        if ($f_path === $path) {
            return;
        } else {
            $r_path = $this->stripPrefix($path);
        }
        $p_path = rtrim($this->prefixPath(''), '\\/');
        $parts = array_filter(explode('/', ltrim($r_path, '/')));
        for ($i = 0, $count = count($parts); $i < $count; ++$i) {
            $f_path = $f_path . '/' . $parts[$i];
            if ($this->documentExist($f_path) == false) {
                $this->createFolder($p_path, $parts[$i]);
            }
            $p_path = $p_path . '/' . $parts[$i];
        }
    }

    protected function createFolder($parentPath, $folderName, array $properties = null) {
        $this->createDocument($parentPath, 'Folder', $folderName, $properties);
    }

    /**
     * Create a new stream based on the input type.
     *
     * This factory accepts the same associative array of options as described
     * in the constructor.
     *
     * @param resource|string|StreamInterface $resource Entity body data
     * @param array                           $options  Additional options
     *
     * @return Stream
     * @throws \InvalidArgumentException if the $resource arg is not valid.
     */
    public function streamFactory($resource = '', array $options = []) {
        $type = gettype($resource);

        if ($type == 'string') {
            $stream = fopen('php://temp', 'r+');
            if ($resource !== '') {
                fwrite($stream, $resource);
                fseek($stream, 0);
            }
            return new GuzzleStream($stream, $options);
        }

        if ($type == 'resource') {
            return new GuzzleStream($resource, $options);
        }

        if ($resource instanceof StreamInterface) {
            return $resource;
        }

        throw new \InvalidArgumentException('Invalid resource type: ' . $type);
    }

}
