<?php

namespace App\Command;

use App\Service\OpenAIEmbeddingGenerator;
use App\Service\LLPhantVectorDBService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class TestSearchCommand extends Command
{
    protected static $defaultName = 'app:test-search';

    private OpenAIEmbeddingGenerator $embeddingGenerator;
    private LLPhantVectorDBService $vectorDBService;

    public function __construct(
        OpenAIEmbeddingGenerator $embeddingGenerator,
        LLPhantVectorDBService $vectorDBService
    ) {
        parent::__construct();
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorDBService = $vectorDBService;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Tests the vector search functionality.')
            ->addArgument('query', InputArgument::REQUIRED, 'The search query string.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = $input->getArgument('query');

        if (empty($query)) {
            $io->error('Query cannot be empty.');
            return Command::INVALID;
        }

        $io->title("Performing search for: \"{$query}\"");

        try {
            // 1. Generate query embedding
            $io->text('Generating embedding for the query...');
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($query);
            if (empty($queryEmbedding)) {
                $io->error('Failed to generate query embedding.');
                return Command::FAILURE;
            }
            $io->text('Query embedding generated.');

            // 2. Perform search
            $io->text('Searching for similar products (top 5)...');
            // Using the default limit of 5 as assumed in LLPhantVectorDBService
            $results = $this->vectorDBService->searchSimilarProducts($queryEmbedding, 5);

            if (empty($results)) {
                $io->warning('No similar products found.');
            } else {
                $io->success(count($results) . ' similar products found:');
                $tableHeaders = ['ID', 'Name']; // Assuming 'score' might not be there yet
                $tableRows = [];
                foreach ($results as $productData) {
                    // $productData is assumed to be an array like ['id' => ..., 'name' => ...]
                    // as constructed in LLPhantVectorDBService
                    $tableRows[] = [
                        $productData['id'] ?? 'N/A',
                        $productData['name'] ?? 'N/A',
                        // $productData['score'] ?? 'N/A' // If score becomes available
                    ];
                }
                $io->table($tableHeaders, $tableRows);
            }

        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            $io->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
