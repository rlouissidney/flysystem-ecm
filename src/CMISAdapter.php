<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
declare(strict_types=1);

namespace UJML3\Flysystem\Ecm;

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
use GuzzleHttp\Stream\Stream as GuzzleStream;
use Dkd\PhpCmis\Enum\UnfileObject;
use Dkd\PhpCmis\Enum\Updatability;

/**
 * Description of CMISAdapter
 *
 * @author r.louis-sidney
 */
class CMISAdapter implements FilesystemAdapter {

    /**
     * Key option for CMIS properties to use when creating new Folder or Document.
     * Expects: associative array of CMIS properties => values.
     * Default: [] (minimum required properties are handled internally).
     *
     * @var string
     */
    const OPTION_PROPERTIES = 'cmis_properties';

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
    const OPTION_AUTO_CREATE_DIRECTORIES = 'cmis_auto_create_directories';

    /**
     * A php-cmis session.
     *
     * @var Client
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param Session $session a php-cmis active session
     * @param string  $prefix  a prefix for all subsequent paths
     */
    public function __construct($prefix = null, $CMIS_BROWSER_URL = 'http://localhost:8080/nuxeo/json/cmis', $CMIS_BROWSER_USER = 'Administrator', $CMIS_BROWSER_PASSWORD = 'Administrator', $CMIS_REPOSITORY_ID = 'default') {

        $httpInvoker = new \GuzzleHttp\Client(
                [
            'auth' =>
            [
                $CMIS_BROWSER_USER,
                $CMIS_BROWSER_PASSWORD
            ]
                ]
        );

        $parameters = [
            \Dkd\PhpCmis\SessionParameter::BINDING_TYPE => \Dkd\PhpCmis\Enum\BindingType::BROWSER,
            \Dkd\PhpCmis\SessionParameter::BROWSER_URL => $CMIS_BROWSER_URL,
            \Dkd\PhpCmis\SessionParameter::BROWSER_SUCCINCT => false,
            \Dkd\PhpCmis\SessionParameter::HTTP_INVOKER_OBJECT => $httpInvoker,
        ];

        $sessionFactory = new \Dkd\PhpCmis\SessionFactory();

// If no repository id is defined use the first repository
        if ($CMIS_REPOSITORY_ID === null) {
            $repositories = $sessionFactory->getRepositories($parameters);
            $parameters[\Dkd\PhpCmis\SessionParameter::REPOSITORY_ID] = $repositories[0]->getId();
        } else {
            $parameters[\Dkd\PhpCmis\SessionParameter::REPOSITORY_ID] = $CMIS_REPOSITORY_ID;
        }

        $session = $sessionFactory->createSession($parameters);
        $this->client = $session;
        $this->prefix = $prefix == null ? '/' : $prefix . '/';
        $this->mimeTypeDetector = new FinfoMimeTypeDetector();
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
            $document = $this->client->getObjectByPath($sourcePath);
        } catch (\Exception $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
        $destinationPath = $this->prefixPath($destination);
        $targetPath = dirname($destinationPath);
        $name = basename($destinationPath);
        try {
            $this->delete($this->stripPrefix($destinationPath));
        } catch (\Exception $ex) {
            
        }
        try {
            $this->ensureDirectory($targetPath, $config);
            $contents = $this->readStream($this->stripPrefix($sourcePath));
            $this->createDocument($targetPath, $name, $contents, $config, $this->getUpdatableProperties($document));
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage());
        }
    }

    public function delete(string $path): void {
        $location = $this->prefixPath($path);
        try {
            $document = $this->client->getObjectByPath($location);
            $this->client->delete($this->client->createObjectId($document->getId()), true);
        } catch (\Exception $e) {
            
        }
    }

    public function deleteDirectory(string $path): void {
        $location = $this->prefixPath($path);
        if ($this->documentExist($location)) {
            try {
                $folder = $this->client->getObjectByPath($location);
                // $folder->delete(true);
                $folder->deleteTree(true, new UnfileObject(UnfileObject::DELETE), true);
            } catch (\Exception $e) {
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
            $document = $this->client->getObjectByPath($location);
            if ($document instanceof \Dkd\PhpCmis\Data\FolderInterface) {
                throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage());
            }
            $fileSize = $document->getPropertyValue('cmis:contentStreamLength');
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
            $document = $this->client->getObjectByPath($location);
            $lastModified = $document->getPropertyValue('cmis:lastModificationDate');
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage());
        }
        if ($lastModified == null) {
            throw UnableToRetrieveMetadata::lastModified($path, '');
        }
        $lastModified = $this->convertDate($lastModified);
        return new FileAttributes($path, null, null, $lastModified);
    }

    public function getObjectAttributes($object, $path) {
        $o_path = $path == '' ? $object->getPropertyValue('cmis:name') : $path . '/' . $object->getPropertyValue('cmis:name');
        $lastModified = $this->convertDate($object->getPropertyValue('cmis:lastModificationDate'));
        $isDirectory = $object instanceof \Dkd\PhpCmis\Data\FolderInterface;
        if ($isDirectory == false) {
            $size = $object->getPropertyValue('cmis:contentStreamLength');
        } else {
            $size = null;
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
            $object = $this->client->getObjectByPath($location);
            $results = [];
            if ($object instanceof \Dkd\PhpCmis\Data\FolderInterface) {
                $childrenList = $object->getChildren();
                foreach ($childrenList as $childObject) {
                    $result = $this->getObjectAttributes($childObject, $path);
                    $results[] = $result;
                    if ($deep && $object instanceof \Dkd\PhpCmis\Data\FolderInterface) {
                        $results = array_merge($results, $this->listDirContents($result->path(), true));
                    }
                }
            }
        } catch (\Exception $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
        return $results;
    }

    public function mimeType(string $path): FileAttributes {
        $location = $this->prefixPath($path);
        try {
            $document = $this->client->getObjectByPath($location);
            $mimeType = $document->getPropertyValue('cmis:contentStreamMimeType');
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage());
        }
        return new FileAttributes($path, null, null, null, $mimeType);
    }

    public function move(string $source, string $destination, Config $config): void {
        $sourcePath = $this->prefixPath($source);
        try {
            $document = $this->client->getObjectByPath($sourcePath);
        } catch (\Exception $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
        $destinationPath = $this->prefixPath($destination);
        $targetPath = dirname($destinationPath);
        $name = basename($destinationPath);
        $this->ensureDirectory($targetPath, $config);
        try {
            $this->delete($this->stripPrefix($destinationPath));
        } catch (\Exception $ex) {
            
        }
        try {
            $contents = $this->readStream($this->stripPrefix($sourcePath));
            $this->createDocument($targetPath, $name, $contents, $config, $this->getUpdatableProperties($document));
            $this->delete($this->stripPrefix($sourcePath));
        } catch (\Exception $e) {
            throw UnableToMoveFile::fromLocationTo($sourcePath, $destinationPath, $e);
        }
    }

    public function read(string $path): string {
        $location = $this->prefixPath($path);
        try {
            $document = $this->client->getObjectByPath($location);
            $contentStream = $this->client->getContentStream(
                    $this->client->createObjectId($document->getId())
            );
            return $contentStream->getContents();
        } catch (\Exception $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
        return null;
    }

    public function readStream(string $path) {
        $location = $this->prefixPath($path);
        try {
            $document = $this->client->getObjectByPath($location);
            $contentStream = $this->client->getContentStream(
                    $this->client->createObjectId($document->getId())
            );
            $resource = \GuzzleHttp\Psr7\StreamWrapper::getResource($contentStream);
            return $resource;
        } catch (\Exception $e) {
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
            $this->client->getObjectByPath($location);
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage());
        }
        $visibility = 'public';
        return new FileAttributes($path, null, $visibility);
    }

    public function write(string $path, string $contents, Config $config): void {
        $this->writeStream($path, $contents, $config);
    }

    public function writeStream($path, $contents, Config $config): void {
        $location = $this->prefixPath($path);
        $parentPath = dirname($location);
        $filename = basename($location);

        // Une copie écrase le document
        try {
            $document = $this->client->getObjectByPath($location);
            $this->client->delete($this->client->createObjectId($document->getId()), true);
        } catch (\Exception $e) {
            
        }
        try {
            $this->createDocument($parentPath, $filename, $contents, $config);
        } catch (\Exception $e) {
            throw UnableToWriteFile::atLocation($location, $e->getMessage());
        }
    }

    public function getClient() {
        return $this->client;
    }

    public function ensureDirectory($path, Config $config) {
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

    public function createFolder($parentPath, $folderName, $properties = array()) {
        $parentFolder = $this->client->getObjectByPath($parentPath);
        $properties['cmis:name'] = $folderName;

        if (!array_key_exists('cmis:objectTypeId', $properties)) {
            $properties['cmis:objectTypeId'] = 'cmis:folder';
        }
        $folderId = $this->client->createFolder(
                $properties,
                $this->client->createObjectId($parentFolder->getId())
        );
        return $this->client->getObject($folderId);
    }

    public function documentExist($path) {
        try {
            $this->client->getObjectByPath($path);
        } catch (\Exception $ex) {
            return false;
        }
        return true;
    }

    public function createDocument($parentPath, $name, $contents, $config, $mergeProperties = array()) {
        try {
            $properties = array_merge($mergeProperties, $config->get(self::OPTION_PROPERTIES) ?: []);
            $this->ensureDirectory($parentPath, $config);
            $parentFolder = $this->client->getObjectByPath($parentPath);
            $properties['cmis:name'] = $name;
            if (!array_key_exists('cmis:objectTypeId', $properties)) {
                $properties['cmis:objectTypeId'] = 'cmis:document';
            }
            if (!array_key_exists('cmis:contentStreamFileName', $properties)) {
                $properties['cmis:contentStreamFileName'] = $name;
            }
            if (!array_key_exists('cmis:contentStreamMimeType', $properties)) {
                $mimeType = $this->mimeTypeDetector->detectMimeType($name, $contents);
                $properties['cmis:contentStreamMimeType'] = $mimeType;
            }
            $this->client->createDocument(
                    $properties,
                    $this->client->createObjectId($parentFolder->getId()),
                    GuzzleStream::factory($contents)
            );
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function getUpdatableProperties($document) {
        $properties = [];
        if (!$document instanceof \Dkd\PhpCmis\Data\DocumentInterface) {
            return $properties;
        }
        $description = $document->getPropertyValue('cmis:description');
        if ($description != null) {
            $properties['cmis:description'] = $description;
        }
        return $properties;
    }

}
