<?php

namespace App\Command;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\ZillizVectorDBService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-search',
    description: 'Test the product search functionality with a natural language query',
)]
class TestSearchCommand extends Command
{
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private ZillizVectorDBService $vectorDBService;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        ZillizVectorDBService $vectorDBService
    ) {
        parent::__construct();
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorDBService = $vectorDBService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Natural language search query');
    }

    /**
     * Executes the console command to search for products matching a natural language query.
     *
     * Retrieves the query argument, generates its embedding, searches for similar products, and displays the results in a table. Returns a success or failure status based on the outcome.
     *
     * @return int Command exit status (success or failure).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = $input->getArgument('query');

        $io->title('Testing product search');
        $io->section('Search query: ' . $query);

        try {
            // Generate embedding for the query
            $io->text('Generating embedding for the query...');
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($query);
            $io->success('Successfully generated embedding for the query');

            // Search for similar products
            $io->text('Searching for similar products...');
            $results = $this->vectorDBService->searchSimilarProducts($queryEmbedding, 5);
            
            if (empty($results)) {
                $io->warning('No products found matching the query');
                return Command::SUCCESS;
            }
            
            $io->success(sprintf('Found %d products matching the query', count($results)));
            
            // Display results
            $io->section('Search results:');
            $table = [];
            foreach ($results as $index => $result) {
                $table[] = [
                    $index + 1,
                    $result['id'] ?? 'N/A',
                    $result['title'] ?? 'Unknown product',
                    $result['distance'] ?? 'N/A',
                ];
            }
            
            $io->table(['#', 'ID', 'Product Name', 'Similarity Score'], $table);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred during search: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
