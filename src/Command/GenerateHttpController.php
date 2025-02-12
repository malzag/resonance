<?php

declare(strict_types=1);

namespace Distantmagic\Resonance\Command;

use Distantmagic\Resonance\Attribute\ConsoleCommand;
use Distantmagic\Resonance\Attribute\Singleton;
use Distantmagic\Resonance\Command;
use Distantmagic\Resonance\HttpInterceptableInterface;
use Distantmagic\Resonance\HttpResponder\HttpController;
use Distantmagic\Resonance\HttpResponderInterface;
use Distantmagic\Resonance\SingletonCollection;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\Printer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[ConsoleCommand(
    name: 'generate:http-controller',
    description: 'Generate http-controller'
)]
final class GenerateHttpController extends Command
{
    public function __construct(private readonly Printer $printer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('class_name', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $className = $input->getArgument('class_name');

        if (!is_string($className)) {
            $output->writeln('<error>class_name is not a string</error>');

            return Command::FAILURE;
        }

        $outputFilename = DM_APP_ROOT.'/HttpResponder/'.$className.'.php';

        if (file_exists($outputFilename)) {
            $output->writeln(sprintf('<error>File already exists: %s</error>', $outputFilename));

            return Command::FAILURE;
        }

        $classCode = $this->generateHttpResponderCode($className);

        file_put_contents($outputFilename, $classCode);

        $output->writeln(sprintf('<info>Controller successfully created: %s</info>', $outputFilename));

        return Command::SUCCESS;
    }

    private function generateHttpResponderCode(string $className): string
    {
        $phpFile = new PhpFile();
        $phpFile->setStrictTypes();

        $namespace = $phpFile->addNamespace('App\\HttpResponder');
        $namespace->addUse(HttpInterceptableInterface::class);
        $namespace->addUse(HttpController::class);
        $namespace->addUse(HttpResponderInterface::class);
        $namespace->addUse(Request::class);
        $namespace->addUse(Response::class);
        $namespace->addUse(Singleton::class);
        $namespace->addUse(SingletonCollection::class);

        $class = $namespace->addClass($className);
        $class->addAttribute(Singleton::class, [
            'collection' => new Literal('SingletonCollection::HttpResponder'),
        ]);
        $class->setExtends(HttpController::class);
        $class->setFinal(true);
        $class->setReadOnly(true);

        $method = $class->addMethod('handle');
        $method->setReturnType(implode('|', [
            'null',
            HttpInterceptableInterface::class,
            HttpResponderInterface::class,
        ]));
        $method->addParameter('request')->setType(Request::class);
        $method->addParameter('response')->setType(Response::class);
        $method->setBody(<<<'CODE'
        $response->end('Hello, Resonance!');

        return null;
        CODE);

        return $this->printer->printFile($phpFile);
    }
}
