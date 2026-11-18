<?php
// Where NP's "WhatsApp" image harvest takes its images from (POST wa_source,
// carried index.php → WaFetch → confirm.php → process_whatsapp.php):
//   'group'   — downloaded from a WhatsApp group by WaFetch: the fetcher's
//               output/images/<shop> (C:\RetailAgents\WhatsAppMessageFetcher)
//   'webhook' — the WhatsApp Business webhook's whatsapp_app/whatsapp_images
//               (the default, so older forms keep working)
// One source per run: the two number their images independently.
require_once __DIR__ . '/../WaFetch/config.php';

function npWaSource(): string
{
    return ($_POST['wa_source'] ?? '') === 'group' ? 'group' : 'webhook';
}

// A group download goes to the shop's own folder (output/images/<shop>), so NP
// runs of several shops at once never take each other's images.
function npWaImagesDir(string $source, string $shop): string
{
    return $source === 'group' ? waf_images_dir($shop) : __DIR__ . '/../whatsapp_app/whatsapp_images';
}

function npWaSourceLabel(string $source): string
{
    return $source === 'group' ? 'WhatsApp — הורדה מקבוצה' : 'WhatsApp Business (webhook)';
}
