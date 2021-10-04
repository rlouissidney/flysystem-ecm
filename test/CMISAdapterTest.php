<?php

declare(strict_types = 1);

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

declare(strict_types = 1);

namespace UJML3\Flysystem\Ecm;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use  UJML3\Flysystem\Ecm\CMISAdapter;

if (file_exists($a = __DIR__ . '/../../../autoload.php')) {
    require_once $a;
} else {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * Description of CMISAdapterTest
 *
 * @author r.louis-sidney
 */
class CMISAdapterTest extends FilesystemAdapterTestCase {

    protected static function createFilesystemAdapter(): FilesystemAdapter {
       /**
        * Afresco  
        * $cmisAdapter = new CMISAdapter('/Shared/FolderTest',
        * 'http://localhost:8080/alfresco/api/-default-/public/cmis/versions/1.1/browser',
        * 'admin',
        * 'admin',
        * '-default-');
        */

        $cmisAdapter = new CMISAdapter('/default-domain/workspaces/FolderTest');
        return $cmisAdapter;
    }

}
