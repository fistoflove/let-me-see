<?php

namespace LetMeSee;

class HtmlComposer
{
    /**
     * Combine HTML and CSS into a complete HTML document
     * 
     * @param string $html User-provided HTML content
     * @param string|null $css User-provided CSS
     * @param bool $stripScripts Whether to strip script tags for security
     * @return string Complete HTML document
     */
    public function compose(string $html, ?string $css = null, bool $stripScripts = true): string
    {
        // Strip script tags if requested
        if ($stripScripts) {
            $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        }

        // Build the complete HTML document
        $document = '<!DOCTYPE html>' . "\n";
        $document .= '<html lang="en">' . "\n";
        $document .= '<head>' . "\n";
        $document .= '    <meta charset="UTF-8">' . "\n";
        $document .= '    <meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
        $document .= '    <title>Screenshot Render</title>' . "\n";
        
        if ($css) {
            $document .= '    <style>' . "\n";
            $document .= $css . "\n";
            $document .= '    </style>' . "\n";
        }
        
        $document .= '</head>' . "\n";
        $document .= '<body>' . "\n";
        $document .= $html . "\n";
        $document .= '</body>' . "\n";
        $document .= '</html>';

        return $document;
    }

    /**
     * Save the composed HTML to a temporary file
     * 
     * @param string $html Complete HTML document
     * @param string $jobId Job identifier
     * @param string $storagePath Base storage path
     * @return string Path to the saved HTML file
     */
    public function saveToFile(string $html, string $jobId, string $storagePath): string
    {
        $jobDir = $storagePath . '/' . $jobId;
        
        if (!is_dir($jobDir)) {
            mkdir($jobDir, 0755, true);
        }

        $htmlPath = $jobDir . '/render.html';
        file_put_contents($htmlPath, $html);

        return $htmlPath;
    }
}
