<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInite74964e353f18bbd310345279c6f7183
{
    public static $prefixLengthsPsr4 = array (
        'W' => 
        array (
            'Wptool\\' => 7,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Wptool\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInite74964e353f18bbd310345279c6f7183::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInite74964e353f18bbd310345279c6f7183::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInite74964e353f18bbd310345279c6f7183::$classMap;

        }, null, ClassLoader::class);
    }
}