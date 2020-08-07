<?php
/**
 * Created by PhpStorm.
 * User: tim
 * Date: 4/14/16
 * Time: 2:17 PM
 */

namespace Transip\Command;

use AstReverter\AstReverter;
use Link0\Profiler\PersistenceHandler\MongoDbHandler;
use Link0\Profiler\PersistenceHandler\MongoDbHandler\MongoClient;
use Link0\Profiler\Profiler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Transip\Dumper\StringDumper;
use Transip\Reducer\PhpAstSourceReducer;
use Transip\Scope\ArrayVariableScope;

class PhpAstFileCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('php-ast:file')
            ->setDescription('Print an deobfuscated version of file')
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Which file do I need to parse?'
            )
            ->addOption(
                'ast',
                null,
                InputOption::VALUE_NONE,
                'print an ast or something pretty'
            )
            ->addOption(
                'variables',
                null,
                InputOption::VALUE_NONE,
                'whether or not to print parsed variables'
            )
            ->addOption(
                'iterations',
                null,
                InputOption::VALUE_OPTIONAL,
                'the maximum amount of transformation iterations to perform',
                1
            )
            ->addOption(
                'tideways',
                null,
                InputOption::VALUE_NONE,
                'enable tideways profiler'
            )
            ->addOption(
                'mongo-host',
                null,
                InputOption::VALUE_OPTIONAL,
                'The MongoDB host',
                '127.0.0.1'
            )
            ->addOption(
                'mongo-port',
                null,
                InputOption::VALUE_OPTIONAL,
                'The MongoDB port',
                '27017'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tideways = $input->getOption('tideways');
        $profiler = null;
        if ($tideways) {
            $mongoHost = $input->getOption('mongo-host');
            $mongoPort = $input->getOption('mongo-port');

            $connectionAddress = "mongodb://{$mongoHost}:{$mongoPort}";
            $mongoClient = new MongoClient($connectionAddress);
            $persistenceHandler = new MongoDbHandler($mongoClient);

            $options = [
                'ignored_functions' => [
                    'PhpParser\NodeTraverser::traverseNode',
                    'PhpParser\NodeTraverser::traverseArray',
                ],
            ];

            $profiler = new Profiler($persistenceHandler, 0, $options);

            $profiler->start();
        }

        $path = $input->getArgument('path');

        $originalFileContent = file_get_contents($path);

        $maxIterations = intval($input->getOption('iterations'));

        $sourceReducer = new PhpAstSourceReducer();
        $variableMap = new ArrayVariableScope();
        $stmts = $sourceReducer->reduceSource($originalFileContent, $maxIterations, $variableMap);

        if ($input->getOption('variables')) {
            var_dump($variableMap);
        }

        if ($input->getOption('ast')) {
            $output->writeln(StringDumper::ast_dump($stmts));
        } else {
            $reverter = new AstReverter();
            $output->writeln($reverter->getCode($stmts), OutputInterface::OUTPUT_RAW);
        }

        if ($tideways && $profiler instanceof Profiler) {
            $profiler->stop();
        }
    }


}
