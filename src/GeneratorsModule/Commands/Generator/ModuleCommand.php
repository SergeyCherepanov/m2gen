<?php

namespace Jcowie\GeneratorsModule\Commands\Generator;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

use Jcowie\Generators\Type\Module as Generator;

class ModuleCommand extends Command
{
    /** @var \Jcowie\Generators\Type\Module $generator */
    private $generator;

    /**
     * @param \Jcowie\Generators\Type\Module $generator
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('generate:module');
        $this->setDescription('Build a magento 2 module from the command line');
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'Module Name ( Test/Module/ ) '
        );
        parent::configure();
    }

    protected function generate(InputInterface $input, OutputInterface $output)
    {
        $this->generator->generate($input->getArgument('name'));
        $output->writeln("Module folder created");
    }
}
