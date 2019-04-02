#!/usr/bin/env php
<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
//use Waliur\Command\Postman\PostmanCommand;

use Postman\PostmanCommand;

$application = new Application();

// ... register commands

$application->add(new PostmanCommand());

$application->run();