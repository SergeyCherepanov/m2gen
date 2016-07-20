<?php

namespace Jcowie\GeneratorsModule\Commands\Generator;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

use Jcowie\Generators\Type\Model as Generator;

class ModelCommand extends Command
{
    /** @var Generator $generator */
    private $generator;

    /**
     * @param Generator $generator
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('generate:model');
        $this->setDescription('Generate model classes');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');
        $moduleName = $helper->ask($input, $output,
            new Question('Module name: ', 'Demo_Hello'));
        $modelName = $helper->ask($input, $output,
            new Question('Model Name: ', 'Item'));

        $moduleName = str_replace('_', '/', $moduleName);

        $fields = [];

        $availableTypes =  [
            'array', 'string', 'int', 'float'
        ];

        $nameQuestion = new Question('New field name (press <return> to stop adding fields): ', '');
        $typeQuestion = new Question('Field type [string]: ', 'string');
        while ($fieldName = $helper->ask($input, $output, $nameQuestion)) {
            $fieldType = null;
            while (!in_array($fieldType, $availableTypes)) {
                $fieldType = $helper->ask($input, $output, $typeQuestion);
            }
            $fields[] = [
                'name' => $fieldName,
                'type' => $fieldType,
            ];
        }
        
        $this->generator->generate($moduleName, $modelName, $fields);
        $output->writeln("Model generated successfully!");
    }
}
