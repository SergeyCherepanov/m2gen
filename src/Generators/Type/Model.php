<?php

namespace Jcowie\Generators\Type;

use Memio\Model\Phpdoc\PropertyPhpdoc;
use Memio\Model\Phpdoc\ThrowTag;
use Memio\Model\Phpdoc\VariableTag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Memio\Memio\Config\Build;
use Memio\Model\File;
use Memio\Model\Object;
use Memio\Model\Property;
use Memio\Model\Method;
use Memio\Model\Argument;
use Memio\Model\Contract;
use Memio\Model\Constant;
use Memio\Model\FullyQualifiedName;
use Memio\Model\Phpdoc\ApiTag;
use Memio\Model\Phpdoc\Description;
use Memio\Model\Phpdoc\DeprecationTag;
use Memio\Model\Phpdoc\StructurePhpdoc;
use Memio\Model\Phpdoc\ParameterTag;
use Memio\Model\Phpdoc\ReturnTag;
use Memio\Model\Phpdoc\MethodPhpdoc;
use Magento\Framework\Config\Dom\UrnResolver;

class Model
{
    /** @var \Symfony\Component\Filesystem\Filesystem $filesystem */
    protected $filesystem;
    protected $basePath;
    protected $modulePath;
    /** @var  CamelCaseToSnakeCaseNameConverter  */
    protected $converter;

    /**
     * ModuleFolderGenerator constructor.
     * @param \Symfony\Component\Filesystem\Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->basePath = BP;
        $this->codePath = BP . "/app/code";
        $this->converter = new CamelCaseToSnakeCaseNameConverter();
    }

    /**
     * @param array $fields
     * @return array
     */
    protected function normalizeFields(array &$fields)
    {
        $converter = new CamelCaseToSnakeCaseNameConverter();
        foreach ($fields as $key => $fieldDefinition) {
            if (strpos($fieldDefinition['name'], '_')) {
                $fields[$key]['name'] = strtolower($fieldDefinition['name']);
            } else {
                $fields[$key]['name'] = $converter->normalize($fieldDefinition['name']);
            }
        }

        return $fields;
    }

    /**
     * @param array $fields
     * @return array
     */
    protected function addMandatoryFields(array &$fields)
    {
        $fields = array_merge([
            [
                'name' => 'id',
                'type' => 'integer',
            ],
            [
                'name' => 'created_at',
                'type' => 'integer',
            ],
            [
                'name' => 'updated_at',
                'type' => 'integer',
            ],
        ], $fields);

        return $fields;
    }

    /**
     * @param $modulePath
     * @param $moduleName
     * @param $modelName
     * @param array $fields
     * @return $this
     */
    protected function generateDataObjectInterface($modulePath, $moduleName, $modelName, array $fields)
    {
        $contract = Contract::make(str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}Interface"));
        foreach ($fields as $fieldDefinition) {
            $fieldName = $fieldDefinition['name'];
            $fieldType = $fieldDefinition['type'];

            $contract->addConstant(Constant::make(strtoupper($fieldName), "'{$fieldName}'"));
            $contract->addMethod(
                Method::make($this->converter->denormalize('set_' . $fieldName))
                    ->setPhpdoc(
                        MethodPhpdoc::make()
                            ->addParameterTag(new ParameterTag($fieldType, 'value'))
                            ->setReturnTag(new ReturnTag($contract->getName())
                        )
                    )
                    ->addArgument(new Argument($fieldType, 'value'))
            );
            $contract->addMethod(
                Method::make($this->converter->denormalize('get_' . $fieldName))
                    ->setPhpdoc(
                        MethodPhpdoc::make()
                            ->setReturnTag(new ReturnTag($fieldType)
                            )
                    )
            );
        }

        $this->dumpContract($contract, "{$modulePath}/Api/Data/{$modelName}Interface.php");

        return $this;
    }

    /**
     * @param $modulePath
     * @param $moduleName
     * @param $modelName
     * @param array $fields
     * @return $this
     */
    protected function generateDataSearchResultInterface($modulePath, $moduleName, $modelName, array $fields)
    {
        $contractName       = basename($modelName) . "SearchResultsInterface";
        $modelContractName  = basename($modelName) . 'Interface';
        $contract           = Contract::make(
            str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}SearchResultsInterface")
        );
        $contract->extend(Contract::make('Magento\\Framework\\Api\\SearchResultsInterface'));


        $contract->addMethod(
            Method::make('getItems')
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->setReturnTag(new ReturnTag($modelContractName . '[]')
                    )
                )
            );

        $contract->addMethod(
            Method::make('setItems')
                ->addArgument(new Argument('array', 'items'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag($modelContractName . '[]', 'items'))
                        ->setReturnTag(new ReturnTag($contractName))
                )
            );

        $this->dumpContract($contract, "{$modulePath}/Api/Data/{$modelName}SearchResultsInterface.php");

        return $this;
    }

    /**
     * @param $modulePath
     * @param $moduleName
     * @param $modelName
     * @param array $fields
     * @return $this
     */
    protected function generateModelStructure($modulePath, $moduleName, $modelName, array $fields)
    {
        $model = Object::make(str_replace('/', '\\', "{$moduleName}/Model/{$modelName}"));
        $model->extend(Object::make('Magento\\Framework\\Model\\AbstractModel'));
        $model->implement(Contract::make(str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}Interface")));

        $_constructBody = '        $this->_init(\''
            . str_replace('/', '\\\\', "{$moduleName}/Model/ResourceModel/{$modelName}")
            . '\');';

        $model->addMethod(
            Method::make('_construct')
                ->setBody($_constructBody)
                ->setPhpdoc(
                    MethodPhpdoc::make()->setDescription(new Description('Initialize resource model(s)'))
                )

        );

        foreach ($fields as $fieldDefinition) {
            $fieldName = $fieldDefinition['name'];
            $fieldType = $fieldDefinition['type'];

            $model->addMethod(
                Method::make($this->converter->denormalize('set_' . $fieldName))
                    ->setPhpdoc(
                        MethodPhpdoc::make()
                            ->addParameterTag(new ParameterTag($fieldType, 'value'))
                            ->setReturnTag(new ReturnTag($model->getName())
                            )
                        )
                    ->setBody(
                        str_repeat(' ', 8)
                        . 'return $this->setData(self::'.strtoupper($fieldName).', $value);')
                    ->addArgument(new Argument($fieldType, 'value'))
            );
            $model->addMethod(
                Method::make($this->converter->denormalize('get_' . $fieldName))
                    ->setBody(
                        str_repeat(' ', 8)
                        . 'return $this->getData(self::'.strtoupper($fieldName).');')
                    ->setPhpdoc(
                        MethodPhpdoc::make()
                            ->setReturnTag(new ReturnTag($fieldType))
                        )
            );
        }

        $this->dumpObject($model, "{$modulePath}/Model/{$modelName}.php");

        return $this;
    }

    /**
     * @param $moduleName
     * @param $modelName
     * @return string
     */
    protected function getTableName($moduleName, $modelName)
    {
        return strtolower(str_replace('/', '_', $moduleName)) . '_' . strtolower(str_replace('/', '_', $modelName));
    }

    /**
     * @param $modulePath
     * @param $moduleName
     * @param $modelName
     * @param array $fields
     * @return $this
     */
    protected function generateResourceModelStructure($modulePath, $moduleName, $modelName, array $fields)
    {
        $resourceModel = Object::make(str_replace('/', '\\', "{$moduleName}/Model/ResourceModel/{$modelName}"));
        $resourceModel->extend(Object::make('Magento\\Framework\\Model\\ResourceModel\\Db\\AbstractDb'));

        $_constructBody = '        $this->_init(\''. $this->getTableName($moduleName, $modelName) .'\', \'id\');';

        $resourceModel->addMethod(
            Method::make('_construct')
                ->setBody($_constructBody)
                ->setPhpdoc(
                    MethodPhpdoc::make()->setDescription(new Description('Initialize table name and id field'))
                )
        );

        $this->dumpObject($resourceModel, "{$modulePath}/Model/ResourceModel/{$modelName}.php", []);

        return $this;
    }

    /**
     * @param $modulePath
     * @param $moduleName
     * @param $modelName
     * @param array $fields
     * @return $this
     */
    protected function generateResourceCollectionStructure($modulePath, $moduleName, $modelName, array $fields)
    {
        $modelFqn  = str_replace('/', '\\', "{$moduleName}/Model/{$modelName}");
        $resourceModelFqn = str_replace('/', '\\', "{$moduleName}/Model/ResourceModel/{$modelName}");
        $collectionModel = Object::make(str_replace('/', '\\', "{$moduleName}/Model/ResourceModel/{$modelName}/Collection"));
        $collectionModel->extend(Object::make('Magento\\Framework\\Model\\ResourceModel\\Db\\Collection\\AbstractCollection'));
        $collectionModel->addMethod(
            Method::make('_construct')
                ->setBody(
                    '        $this->_init(\''.$modelFqn.'\', \''.$resourceModelFqn.'\');'
                )
                ->setPhpdoc(
                    MethodPhpdoc::make()->setDescription(new Description('Initialize table name and id field'))
                )
        );

        $this->dumpObject($collectionModel, "{$modulePath}/Model/ResourceModel/{$modelName}Collection.php", []);

        return $this;
    }

    /**
     * @param $modulePath
     * @param $moduleName
     * @param $modelName
     * @param array $fields
     * @return $this
     */
    protected function generateRepositoryInterface($modulePath, $moduleName, $modelName, array $fields)
    {
        $argumentName                  = strtolower(basename($modelName));
        $modelContractName             = basename($modelName) . 'Interface';
        $modelContractFqn              = str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}Interface");
        $modelSearchResultContractFqn  = str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}SearchResultsInterface");
        $modelSearchResultContractName = basename($modelName) . "SearchResultsInterface";
        $searchCriteriaFdn             = '\\Magento\\Framework\\Api\\SearchCriteriaInterface';
        $searchCriteriaContractName    = 'SearchCriteriaInterface';

        $contract = Contract::make(str_replace('/', '\\', "{$moduleName}/Api/{$modelName}RepositoryInterface"));
        $contract->addMethod(
            Method::make('getById')
                ->addArgument(new Argument('int', 'id'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag('int', 'id'))
                        ->setReturnTag(new ReturnTag($modelContractName))
                        ->addThrowTag(new ThrowTag('\\Magento\\Framework\\Exception\\LocalizedException'))
                )
        );

        $contract->addMethod(
            Method::make('getList')
                ->addArgument(new Argument($searchCriteriaContractName, 'searchCriteria'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag($searchCriteriaContractName, 'searchCriteria'))
                        ->setReturnTag(new ReturnTag($modelSearchResultContractName))
                        ->addThrowTag(new ThrowTag('\\Magento\\Framework\\Exception\\LocalizedException'))
                )
        );

        $contract->addMethod(
            Method::make('save')
                ->addArgument(new Argument($modelContractName, $argumentName))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag($modelContractName, $argumentName))
                        ->setReturnTag(new ReturnTag($modelContractName))
                        ->addThrowTag(new ThrowTag('\\Magento\\Framework\\Exception\\LocalizedException'))
                )
        );

        $contract->addMethod(
            Method::make('delete')
                ->addArgument(new Argument($modelContractName, $argumentName))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag($modelContractName, $argumentName))
                        ->setReturnTag(new ReturnTag('bool'))
                        ->addThrowTag(new ThrowTag('\\Magento\\Framework\\Exception\\LocalizedException'))
                )
        );

        $contract->addMethod(
            Method::make('deleteById')
                ->addArgument(new Argument('int', 'id'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag('int', 'id'))
                        ->setReturnTag(new ReturnTag('bool'))
                        ->addThrowTag(new ThrowTag('\\Magento\\Framework\\Exception\\LocalizedException'))
                        ->addThrowTag(new ThrowTag('\\Magento\\Framework\\Exception\\NoSuchEntityException'))
                )
        );

        $this->dumpContract($contract, "{$modulePath}/Api/{$modelName}RepositoryInterface.php", [
            $modelContractFqn,
            $modelSearchResultContractFqn,
            $searchCriteriaFdn
        ]);

        return $this;
    }

    protected function generateRepositoryModel($modulePath, $moduleName, $modelName, array $fields)
    {
        $resourceModelName     = basename($modelName);
        $resourceModelFqn      = str_replace('/', '\\', "{$moduleName}/Model/ResourceModel/{$modelName}");

        $collectionFactoryName = 'CollectionFactory';
        $collectionFactoryFqn  = str_replace('/', '\\', "{$moduleName}/Model/ResourceModel/{$modelName}/CollectionFactory");

        $searchResultsFactoryName = basename($modelName) . 'SearchResultsInterfaceFactory';
        $searchResultsFactoryFqn  = str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}SearchResultsInterfaceFactory");

        $searchResultsContractName = basename($modelName) . 'SearchResultsInterface';
        $searchResultsContractFqn  = str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}SearchResultsInterface");

        $dataContractName = basename($modelName) . 'Interface';
        $dataContractFqn  = str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}Interface");

        $dataFactoryName = basename($modelName) . 'InterfaceFactory';
        $dataFactoryFqn  = str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}InterfaceFactory");

        $factoryName = basename($modelName) . 'Factory';

        $model = Object::make(str_replace('/', '\\', "{$moduleName}/Model/{$modelName}Repository"));
        $model->implement(Contract::make(str_replace('/', '\\', "{$moduleName}/Api/{$modelName}RepositoryInterface")));

        $__constructBody = '        $this->resource = $resource;
        $this->factory              = $factory;
        $this->collectionFactory    = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->dataFactory          = $dataFactory;
        $this->dataObjectHelper     = $dataObjectHelper;
        $this->dataObjectProcessor  = $dataObjectProcessor;
        $this->storeManager         = $storeManager;';

        $model->addProperty(Property::make('resource')->makeProtected()
            ->setPhpdoc(PropertyPhpdoc::make()->setVariableTag(new VariableTag($resourceModelName))));
        $model->addProperty(Property::make('factory')->makeProtected()
            ->setPhpdoc(PropertyPhpdoc::make()->setVariableTag(new VariableTag($factoryName))));
        $model->addProperty(Property::make('collectionFactory')->makeProtected()
            ->setPhpdoc(PropertyPhpdoc::make()->setVariableTag(new VariableTag($collectionFactoryName))));
        $model->addProperty(Property::make('searchResultsFactory')->makeProtected()
            ->setPhpdoc(PropertyPhpdoc::make()->setVariableTag(new VariableTag($searchResultsFactoryName))));
        $model->addProperty(Property::make('dataFactory')->makeProtected()
            ->setPhpdoc(PropertyPhpdoc::make()->setVariableTag(new VariableTag($dataFactoryName))));
        $model->addProperty(Property::make('dataObjectHelper')->makeProtected()
            ->setPhpdoc(PropertyPhpdoc::make()->setVariableTag(new VariableTag('DataObjectHelper'))));
        $model->addProperty(Property::make('dataObjectProcessor')->makeProtected()
            ->setPhpdoc(PropertyPhpdoc::make()->setVariableTag(new VariableTag('DataObjectProcessor'))));
        $model->addProperty(Property::make('storeManager')->makeProtected()
            ->setPhpdoc(PropertyPhpdoc::make()->setVariableTag(new VariableTag('StoreManagerInterface'))));
        $model->addMethod(
            Method::make('__construct')
                ->setBody($__constructBody)
                ->addArgument(new Argument($resourceModelName, 'resource'))
                ->addArgument(new Argument($factoryName, 'factory'))
                ->addArgument(new Argument($collectionFactoryName, 'collectionFactory'))
                ->addArgument(new Argument($searchResultsFactoryName, 'searchResultsFactory'))
                ->addArgument(new Argument($dataFactoryName, 'dataFactory'))
                ->addArgument(new Argument('DataObjectHelper', 'dataObjectHelper'))
                ->addArgument(new Argument('DataObjectProcessor', 'dataObjectProcessor'))
                ->addArgument(new Argument('StoreManagerInterface', 'storeManager'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag($resourceModelName, 'resource'))
                        ->addParameterTag(new ParameterTag($factoryName, 'factory'))
                        ->addParameterTag(new ParameterTag($collectionFactoryName, 'collectionFactory'))
                        ->addParameterTag(new ParameterTag($searchResultsFactoryName, 'searchResultsFactory'))
                        ->addParameterTag(new ParameterTag($dataFactoryName, 'dataFactory'))
                        ->addParameterTag(new ParameterTag('DataObjectHelper', 'dataObjectHelper'))
                        ->addParameterTag(new ParameterTag('DataObjectProcessor', 'dataObjectProcessor'))
                        ->addParameterTag(new ParameterTag('StoreManagerInterface', 'storeManager'))
                )
        );

        $model->addMethod(
            Method::make('getById')
                ->setBody('        $object = $this->factory->create();
        $this->resource->load($object, $id);
        if (!$object->getId()) {
            throw new NoSuchEntityException("Object not found");
        }

        return $object;')
                ->addArgument(new Argument('int', 'id'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag('int', 'id'))
                        ->setReturnTag(new ReturnTag($dataContractName))
                        ->addThrowTag(new ThrowTag('NoSuchEntityException'))
                )
        );

        $model->addMethod(
            Method::make('save')
                ->setBody('        $this->resource->save($object);

        return $object;')
                ->addArgument(new Argument($dataContractName, 'object'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag($dataContractName, 'object'))
                        ->setReturnTag(new ReturnTag($dataContractName))
                )
        );

        $getListBody = '        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($criteria);

        $collection = $this->collectionFactory->create();
        foreach ($criteria->getFilterGroups() as $filterGroup) {
            foreach ($filterGroup->getFilters() as $filter) {
                $condition = $filter->getConditionType() ?: \'eq\';
                $collection->addFieldToFilter($filter->getField(), [$condition => $filter->getValue()]);
            }
        }
        $searchResults->setTotalCount($collection->getSize());
        $sortOrders = $criteria->getSortOrders();
        if ($sortOrders) {
            /** @var SortOrder $sortOrder */
            foreach ($sortOrders as $sortOrder) {
                $collection->addOrder(
                    $sortOrder->getField(),
                    ($sortOrder->getDirection() == SortOrder::SORT_ASC) ? \'ASC\' : \'DESC\'
                );
            }
        }
        $collection->setCurPage($criteria->getCurrentPage());
        $collection->setPageSize($criteria->getPageSize());
        $objects = [];
        /** @var '.$dataContractName.' $object */
        foreach ($collection as $object) {
            $data = $this->dataFactory->create();
            $this->dataObjectHelper->populateWithArray(
                $data,
                $object->getData(),
                \''.$dataContractFqn.'\'
            );
            $objects[] = $this->dataObjectProcessor->buildOutputDataArray(
                $data,
                \''.$dataContractFqn.'\'
            );
        }
        $searchResults->setItems($objects);

        return $searchResults;';

        $model->addMethod(
            Method::make('getList')
                ->setBody($getListBody)
                ->addArgument(new Argument('SearchCriteriaInterface', 'criteria'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag('SearchCriteriaInterface', 'criteria'))
                        ->setReturnTag(new ReturnTag($searchResultsContractName))
                )
        );

        $model->addMethod(
            Method::make('delete')
                ->setBody('        $this->resource->delete($object);

        return true;')
                ->addArgument(new Argument($dataContractName, 'object'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag($dataContractName, 'object'))
                        ->setReturnTag(new ReturnTag('bool'))
                )
        );

        $model->addMethod(
            Method::make('deleteById')
                ->setBody('        return $this->resource->delete($this->getById($id));')
                ->addArgument(new Argument('int', 'id'))
                ->setPhpdoc(
                    MethodPhpdoc::make()
                        ->addParameterTag(new ParameterTag('int', 'id'))
                        ->setReturnTag(new ReturnTag('bool'))
                )
        );

        $this->dumpObject($model, "{$modulePath}/Model/{$modelName}Repository.php", [
            $resourceModelFqn,
            $collectionFactoryFqn,
            $searchResultsFactoryFqn,
            $dataFactoryFqn,
            $dataContractFqn,
            $searchResultsContractFqn,
            'Magento\\Framework\\Api\\DataObjectHelper',
            'Magento\\Framework\\Reflection\\DataObjectProcessor',
            'Magento\\Framework\\Api\\SortOrder',
            'Magento\\Store\\Model\\StoreManagerInterface',
            'Magento\\Framework\\Exception\\NoSuchEntityException',
            'Magento\\Framework\\Api\\SearchCriteriaInterface'
        ]);

        return $this;
    }

    /**
     * @param Contract $contract
     * @param $filePath
     * @param array $fqns
     * @return $this
     */
    protected function dumpContract(Contract $contract, $filePath, array $fqns = [])
    {
        $fileDir  = dirname($filePath);
        $file     = File::make($filePath);
        foreach ($contract->allContracts() as $_contract) {
            $fqns[] = $_contract->getFullyQualifiedName();
        }
        sort($fqns);
        foreach ($fqns as $name) {
            $file->addFullyQualifiedName(new FullyQualifiedName($name));
        }
        $file->setStructure($contract);
        $this->filesystem->mkdir($fileDir, 0755);
        $this->filesystem->dumpFile($filePath, Build::prettyPrinter()->generateCode($file), 0755);

        return $this;
    }

    /**
     * @param Object $object
     * @param $filePath
     * @param array $fqns
     * @return $this
     */
    protected function dumpObject(Object $object, $filePath, array $fqns = [])
    {
        $fileDir  = dirname($filePath);
        $file     = File::make($filePath);
        if ($object->hasParent()) {
            $fqns[] = $object->getParent()->getFullyQualifiedName();
        }
        foreach ($object->allContracts() as $contract) {
            $fqns[] = $contract->getFullyQualifiedName();
        }
        sort($fqns);
        foreach ($fqns as $name) {
            $file->addFullyQualifiedName(new FullyQualifiedName($name));
        }
        $file->setStructure($object);
        $this->filesystem->mkdir($fileDir, 0755);
        $this->filesystem->dumpFile($filePath, Build::prettyPrinter()->generateCode($file), 0755);

        return $this;
    }

    protected function dumpDiConfig($modulePath, $moduleName, $modelName, array $fields)
    {
        $urnResolver  = new UrnResolver();
        $xsdSchema    = $urnResolver->getRealPath('urn:magento:framework:ObjectManager/etc/config.xsd');
        $xsdValidator = new \Magento\Framework\TestFramework\Unit\Utility\XsdValidator();

        $path = "{$this->codePath}/{$moduleName}/etc/di.xml";
        $config = new \DomDocument('1.0', 'UTF-8');
        if ($this->filesystem->exists($path)) {
            $config->load($path);
        } else {
            $configNode = $config->createElement("config");
            $configNode->setAttributeNS(
                'http://www.w3.org/2000/xmlns/',
                'xmlns:xsi',
                'http://www.w3.org/2001/XMLSchema-instance'
            );
            $configNode->setAttribute('xsi:noNamespaceSchemaLocation', 'urn:magento:framework:ObjectManager/etc/config.xsd');
            $config->appendChild($configNode);
        }

        $xpath = new \DomXpath($config);
        $configNode = $xpath->query('//config')->item(0);

        // Data interface
        $modelFqn    = str_replace('/', '\\', "{$moduleName}/Model/{$modelName}");
        $contractFqn = str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}Interface");

        if (!$xpath->query('//preference[@for="'.$contractFqn.'"]')->length) {
            $preference = $config->createElement("preference");
            $preference->setAttribute('for', $contractFqn);
            $preference->setAttribute('type', $modelFqn);
            $configNode->appendChild($preference);
        }

        // Repository interface
        $modelFqn    = str_replace('/', '\\', "{$moduleName}/Model/{$modelName}Repository");
        $contractFqn = str_replace('/', '\\', "{$moduleName}/Api/{$modelName}RepositoryInterface");

        if (!$xpath->query('//preference[@for="'.$contractFqn.'"]')->length) {
            $preference = $config->createElement("preference");
            $preference->setAttribute('for', $contractFqn);
            $preference->setAttribute('type', $modelFqn);
            $configNode->appendChild($preference);
        }

        // Search result interface
        $modelFqn    = str_replace('/', '\\', "Magento/Framework/Api/SearchResults");
        $contractFqn = str_replace('/', '\\', "{$moduleName}/Api/Data/{$modelName}SearchResultsInterface");

        if (!$xpath->query('//preference[@for="'.$contractFqn.'"]')->length) {
            $preference = $config->createElement("preference");
            $preference->setAttribute('for', $contractFqn);
            $preference->setAttribute('type', $modelFqn);
            $configNode->appendChild($preference);
        }

        $errors = $xsdValidator->validate($xsdSchema, $config->saveXML());
        if (!$errors) {
            $config->formatOutput = true;
            $this->filesystem->dumpFile($path, $config->saveXML());
        } else {
            throw new \Exception(implode(PHP_EOL, $errors));
        }
    }

    /**
     * @param $moduleName
     * @param $modelName
     * @param array $fields
     * @throws \Exception
     */
    public function generate($moduleName, $modelName, array $fields)
    {
        $modulePath = "{$this->codePath}/{$moduleName}";

        if (!is_dir($modulePath)) {
            throw new \Exception("Directory not found: {$modulePath}");
        }

        $this->normalizeFields($fields);
        $this->addMandatoryFields($fields);
        $this->generateDataObjectInterface($modulePath, $moduleName, $modelName, $fields);
        $this->generateDataSearchResultInterface($modulePath, $moduleName, $modelName, $fields);
        $this->generateModelStructure($modulePath, $moduleName, $modelName, $fields);
        $this->generateResourceModelStructure($modulePath, $moduleName, $modelName, $fields);
        $this->generateResourceCollectionStructure($modulePath, $moduleName, $modelName, $fields);
        $this->generateRepositoryInterface($modulePath, $moduleName, $modelName, $fields);
        $this->generateRepositoryModel($modulePath, $moduleName, $modelName, $fields);
        $this->dumpDiConfig($modulePath, $moduleName, $modelName, $fields);
    }
}

