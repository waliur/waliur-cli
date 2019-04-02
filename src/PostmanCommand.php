<?php

// src/Command/CreateUserCommand.php
namespace Postman;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Yaml\Yaml;

class PostmanCommand extends Command
{

    const DOMAIN_LIST_FILE_NAME = '/domains.yml';
    const LETTINGS_OBJECTS_DIRECTORY = '/Lettings';
    const SALES_OBJECTS_DIRECTORY = '/Sales';

    private $collectionTemplate;
    private $collection;

    private $domainList;

    private $propertyObjectLocation;
    private $lettingsPath;
    private $salesPath;

    private $lettingsObjectPaths;
    private $salesObjectPaths;

    private $input;
    private $output;

    // The name of the command (the part after "bin/console")
    protected static $defaultName = 'postman:make-collection';

    protected function configure()
    {

        $folderStructure = <<<EOF
Expected folder structure:

    test-properties/
    ├── domains.yml
    ├── Sales/
    │   ├── property_object.json
    │   ├── property_object.json
    │   └── property_object.json
    ├── Lettings/
    │   ├── property_object.json
    │   ├── property_object.json
    │   └── property_object.json
    └── generated-postman-collections/
        └── dev.waliur.co.uk - Sales.json
        └── localhost - Sales.json
        └── stage.waliur.co.uk - Sales.json
        
* Add all the domains you want to target in domains.yml like so:

    - https://dev.waliur.co.uk
    - https://stage.waliur.co.uk
    - http://localhost

* All generated Postman collections will be placed inside "generated-postman-collections"
* The root folder, "test-properties" can be named anything you want.

EOF;

        $this
            ->setDescription($folderStructure)
            ->addArgument('propertyObjectLocation', InputArgument::REQUIRED, 'Absolute path of properties: /Users/wrahman/Development/test-properties')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->propertyObjectLocation   = $input->getArgument('propertyObjectLocation');

        $lettingsPath                   = $this->propertyObjectLocation . self::LETTINGS_OBJECTS_DIRECTORY;
        $salesPath                      = $this->propertyObjectLocation . self::SALES_OBJECTS_DIRECTORY;
        $yamlDomainListLocation         = $this->propertyObjectLocation . self::DOMAIN_LIST_FILE_NAME;

        if(!file_exists($lettingsPath)){
            throw new \Exception("Directory '{$lettingsPath}' does not exist!");
        }
        if(!file_exists($salesPath)){
            throw new \Exception("Directory '{$salesPath}' does not exist!");
        }
        if(!file_exists($yamlDomainListLocation)){
            throw new \Exception("File '{$yamlDomainListLocation}' does not exist!");
        }

        $this->lettingsPath = $lettingsPath;
        $this->salesPath = $salesPath;

        $this->parseDomainList($yamlDomainListLocation);
        $this->loadPropertyObjectsPaths();
        $this->loadPostmanCollectionTemplate();
        $this->buildCollection();
        $this->saveCollection();
    }

    protected function loadPropertyObjectsPaths(){

        $this->lettingsObjectPaths = scandir($this->lettingsPath);
        $this->salesObjectPaths = scandir($this->salesPath);

        foreach($this->lettingsObjectPaths as $key => $value){
            $this->lettingsObjectPaths[$key] = $this->lettingsPath . '/' .  $value;

            if(!preg_match('/.json$/', $value)){
                unset($this->lettingsObjectPaths[$key]);
            }
        }

        foreach($this->salesObjectPaths as $key => $value) {
            $this->salesObjectPaths[$key] = $this->salesPath . '/' .  $value;

            if(!preg_match('/.json$/', $value)){
                unset($this->salesObjectPaths[$key]);
            }
        }
    }

    protected function loadPostmanCollectionTemplate(){
        $collectionTemplate = file_get_contents(dirname(__DIR__) . '/src/single_collection_template.json');
        $this->collectionTemplate = json_decode($collectionTemplate, true);
    }

    protected function buildCollection(){

        foreach($this->domainList as $domain){
            $protocol   = $domain['protocol'];
            $host       = $domain['host'];

            $this->collection[$host]['Sales']   = $this->buildSalesCollection($protocol, $host);
            $this->collection[$host]['Let']     = $this->buildLettingsCollection($protocol, $host);
        }
    }

    protected function buildSalesCollection($protocol, $host){

        $salesCollection = $this->collectionTemplate;
        $item = array_pop($salesCollection['item']);

        $salesCollection['info']['name'] = "$host - Sales";

        $count = 1;
        foreach($this->salesObjectPaths as $value){
            $item['name'] = "Sale $count";

            $url = parse_url($item['request']['url']['raw']);
            $url = $protocol . '://' . $host . $url['path'] . '?' . $url['query'];
            $item['request']['url']['raw'] = $url; // https://localhost/api/v1/add-property?_format=json&Content-Type=application/json

            $item['request']['url']['protocol'] = $protocol; // e.g https
            $item['request']['url']['host'][0] = $host; // e.g localhost

            $body = file_get_contents($value);
            $item['request']['body']['raw'] = $body; // Read each of them from file and add into this array.

            $salesCollection['item'][] = $item;

            $count++;
        }

        return $salesCollection;
    }

    protected function buildLettingsCollection($protocol, $host){

        $lettingsCollection = $this->collectionTemplate;
        $item = array_pop($lettingsCollection['item']);

        $lettingsCollection['info']['name'] = "$host - Let";

        $count = 1;
        foreach($this->lettingsObjectPaths as $value){
            $item['name'] = "Let $count";

            $url = parse_url($item['request']['url']['raw']);
            $url = $protocol . '://' . $host . $url['path'] . '?' . $url['query'];
            $item['request']['url']['raw'] = $url; // https://localhost/api/v1/add-property?_format=json&Content-Type=application/json

            $item['request']['url']['protocol'] = $protocol; // e.g https
            $item['request']['url']['host'][0] = $host; // e.g localhost

            $body = file_get_contents($value);
            $item['request']['body']['raw'] = $body; // Read each of them from file and add into this array.

            $lettingsCollection['item'][] = $item;

            $count++;
        }

        return $lettingsCollection;
    }

    protected function parseDomainList($yamlDomainListLocation){
        $domains = YAML::parseFile($yamlDomainListLocation);
        foreach ($domains as $url){
            $protocol           = parse_url($url, PHP_URL_SCHEME);
            $host               = parse_url($url, PHP_URL_HOST);
            $this->domainList[] = ['protocol' => $protocol, 'host' => $host];
        }
    }

    protected function saveCollection(){

        foreach ($this->collection as $domain => $data) {

            // Lettings
            $letCollectionFileName = $this->propertyObjectLocation . '/generated-postman-collections' . "/$domain - Let.json";
            $payLoad = json_encode($data['Let']);
            file_put_contents($letCollectionFileName, $payLoad);

            // Sales
            $saleCollectionFileName = $this->propertyObjectLocation . '/generated-postman-collections' . "/$domain - Sales.json";
            $payLoad = json_encode($data['Sales']);
            file_put_contents($saleCollectionFileName, $payLoad);
        }
    }
}