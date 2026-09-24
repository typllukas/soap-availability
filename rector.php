<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/config', __DIR__ . '/public'])
    ->withRootFiles()
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withSkip([
        // a SoapFault carries no numeric code worth forwarding, so only the named previous: stays
        ThrowWithPreviousExceptionRector::class,
        // Symfony Flex matches the fully qualified ::class lines; imported names would duplicate each recipe's entry
        __DIR__ . '/config/bundles.php',
    ]);
