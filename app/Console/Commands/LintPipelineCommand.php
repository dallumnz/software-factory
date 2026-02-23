<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Pipeline\DOTParser;
use App\Pipeline\Lint\Linter;

class LintPipelineCommand extends Command
{
    protected $signature = 'factory:lint {file : The DOT file to lint}';

    protected $description = 'Lint a DOT graph pipeline';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $dot = file_get_contents($file);
        
        $this->info('Parsing DOT graph...');
        $parser = new DOTParser();
        
        try {
            $graph = $parser->parse($dot);
        } catch (\Exception $e) {
            $this->error('Failed to parse DOT: ' . $e->getMessage());
            return 1;
        }

        $this->info('Linting...');
        
        $linter = new Linter();
        $result = $linter->lint($graph);
        
        if ($result['passed']) {
            $this->info('✅ No errors found!');
        } else {
            $this->error('❌ Errors found:');
            foreach ($result['errors'] as $error) {
                $this->line("  ERROR: {$error}");
            }
        }
        
        if (!empty($result['warnings'])) {
            $this->warn('⚠️  Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->line("  {$warning}");
            }
        }
        
        return $result['passed'] ? 0 : 1;
    }
}
