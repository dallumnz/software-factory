<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Pipeline\Stylesheets\ModelStylesheet;

class ListStylesheetsCommand extends Command
{
    protected $signature = 'factory:stylesheets {--model= : Show stylesheet for specific model}';

    protected $description = 'List available model stylesheets';

    public function handle(): int
    {
        $stylesheet = new ModelStylesheet();
        $model = $this->option('model');
        
        if ($model) {
            $config = $stylesheet->get($model);
            $this->info("Stylesheet for {$model}:");
            foreach ($config as $key => $value) {
                $this->line("  {$key}: " . (is_string($value) ? $value : json_encode($value)));
            }
            return 0;
        }
        
        $this->info('Available model stylesheets:');
        $this->newLine();
        
        foreach ($stylesheet->list() as $name => $config) {
            $this->line("  {$name}");
            $this->line("    Temperature: " . ($config['temperature'] ?? 'default'));
            $this->line("    Max tokens: " . ($config['max_tokens'] ?? 'default'));
            if (!empty($config['prompt_prefix'])) {
                $prefix = substr($config['prompt_prefix'], 0, 50);
                $this->line("    Prefix: {$prefix}...");
            }
            $this->newLine();
        }
        
        return 0;
    }
}
