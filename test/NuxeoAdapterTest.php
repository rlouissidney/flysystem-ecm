<?php

declare(strict_types=1);

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

declare(strict_types = 1);

namespace UJML3\Flysystem\Ecm;

if (file_exists($a = __DIR__ . '/../../../autoload.php')) {
    require_once $a;
} else {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use UJML3\Flysystem\Ecm\NuxeoAdapter;

/**
 * Description of EcmAdapterTest
 *
 * @author r.louis-sidney
 */
class NuxeoAdapterTest extends FilesystemAdapterTestCase {

    protected static function createFilesystemAdapter(): FilesystemAdapter {
        $ecmAdapter = new NuxeoAdapter('/default-domain/workspaces/FolderTest');
        return $ecmAdapter;
    }
}
