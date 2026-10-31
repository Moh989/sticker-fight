<?php

namespace App\Console\Commands;

use App\Services\CatalogSnapshot;
use Illuminate\Console\Command;

class ExportCatalogSnapshot extends Command
{
    protected $signature = 'sticker:catalog-export {archive : Destination ZIP; must not exist}';

    protected $description = 'Export real catalog products and images without customer data, credentials or private fonts.';

    public function handle(CatalogSnapshot $catalog): int
    {
        try {
            $result = $catalog->export((string) $this->argument('archive'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Catalog export failed. Check the private application log. Existing files were preserved.');

            return self::FAILURE;
        }
    }
}
