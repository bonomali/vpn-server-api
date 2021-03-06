<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use LC\Common\Config;
use LC\Common\Http\BasicAuthenticationHook;
use LC\Common\Http\Request;
use LC\Common\Http\Response;
use LC\Common\Http\Service;
use LC\Common\Json;
use LC\Common\Logger;
use LC\Common\Random;
use LC\OpenVpn\ManagementSocket;
use LC\Server\Api\CertificatesModule;
use LC\Server\Api\ConnectionsModule;
use LC\Server\Api\InfoModule;
use LC\Server\Api\LogModule;
use LC\Server\Api\OpenVpnModule;
use LC\Server\Api\StatsModule;
use LC\Server\Api\SystemMessagesModule;
use LC\Server\Api\UserMessagesModule;
use LC\Server\Api\UsersModule;
use LC\Server\CA\EasyRsaCa;
use LC\Server\OpenVpn\ServerManager;
use LC\Server\Storage;
use LC\Server\TlsAuth;

$logger = new Logger('vpn-server-api');

try {
    // this is provided by Apache, using CanonicalName
    $request = new Request($_SERVER, $_GET, $_POST);

    $dataDir = sprintf('%s/data', $baseDir);
    $configDir = sprintf('%s/config', $baseDir);

    $config = Config::fromFile(
        sprintf('%s/config.php', $configDir)
    );

    $service = new Service();
    $basicAuthentication = new BasicAuthenticationHook(
        $config->getSection('apiConsumers')->toArray(),
        'vpn-server-backend'
    );
    $service->addBeforeHook('auth', $basicAuthentication);

    $storage = new Storage(
        new PDO(
            sprintf('sqlite://%s/db.sqlite', $dataDir)
        ),
        sprintf('%s/schema', $baseDir)
    );
    $storage->update();

    $service->addModule(
        new ConnectionsModule(
            $config,
            $storage
        )
    );

    $service->addModule(
        new StatsModule(
            $dataDir
        )
    );

    $service->addModule(
        new UsersModule(
            $config,
            $storage
        )
    );

    $service->addModule(
        new InfoModule(
            $config
        )
    );

    $service->addModule(
        new OpenVpnModule(
            new ServerManager($config, $logger, new ManagementSocket()),
            $storage
        )
    );

    $service->addModule(
        new LogModule(
            $storage
        )
    );

    $service->addModule(
        new SystemMessagesModule(
            $storage
        )
    );

    $service->addModule(
        new UserMessagesModule(
            $storage
        )
    );

    $easyRsaDir = sprintf('%s/easy-rsa', $baseDir);
    $easyRsaDataDir = sprintf('%s/easy-rsa', $dataDir);

    $easyRsaCa = new EasyRsaCa(
        $easyRsaDir,
        $easyRsaDataDir
    );
    $tlsAuth = new TlsAuth($dataDir);

    $service->addModule(
        new CertificatesModule(
            $easyRsaCa,
            $storage,
            $tlsAuth,
            new Random()
        )
    );

    $service->run($request)->send();
} catch (Exception $e) {
    $logger->error($e->getMessage());
    $response = new Response(500, 'application/json');
    $response->setBody(Json::encode(['error' => $e->getMessage()]));
    $response->send();
}
