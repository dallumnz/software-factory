<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ListArtifactsCommand extends Command
{
    protected $signature = 'factory:artifacts {--dir= : Artifact directory}';

    protected $description = 'List pipeline artifacts (prompts/responses)';

    public function handle(): int
    {
        $dir = $this->option('dir') ?? storage_path('artifacts');
        
        if (!is_dir($dir)) {
            $this->error("Artifact directory not found: {$dir}");
            return 1;
        }
        
        $files = glob("{$dir}/*.txt");
        
        if (empty($files)) {
            $this->info("No artifacts found in {$dir}");
            return 0;
        }
        
        $this->info("Artifacts in {$dir}:");
        $this->newLine();
        
        foreach ($files as $file) {
            $basename = basename($file);
            $size = filesize($file);
            $modified = date('Y-m-d H:i:s', filemtime($file));
            $this->line("  {$basename}");
            $this->line("    Size: {$size} bytes | Modified: {$modified}");
        }
        
        $this->newLine();
        $this->info("Total: " . count($files) . " artifacts");
        
        return 0;
    }
}
