<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

// Turns the HTML we already render for a document (PO, GRN, gate pass, bill,
// stock report) into a real .xlsx workbook. The old downloads sent that HTML
// with an .xls name, which current Excel refuses or warns about.
// Table rows become sheet rows; text outside tables becomes one-cell rows.
class HtmlToXlsx
{
    private const BLOCKS = ['div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'li', 'section', 'header', 'footer', 'table'];

    /** @var array<int, array<int, string>> */
    private array $rows = [];

    private string $line = '';

    public static function download(string $html, string $filename): StreamedResponse
    {
        $filename = preg_replace('/\.xlsx?$/i', '', $filename).'.xlsx';
        $bytes = self::fromHtml($html);

        return response()->streamDownload(fn () => print ($bytes), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public static function fromHtml(string $html): string
    {
        return self::fromRows((new self)->rowsFromHtml($html));
    }

    /** @return array<int, array<int, string>> */
    public function rowsFromHtml(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $this->rows = [];
        $this->line = '';
        $this->walk($dom->getElementsByTagName('body')->item(0) ?? $dom);
        $this->flush();

        return $this->rows;
    }

    private function walk(DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['style', 'script', 'head', 'title'], true)) {
                    continue;
                }
                if ($tag === 'tr') {
                    $this->flush();
                    $this->row($child);

                    continue;
                }
                if (in_array($tag, self::BLOCKS, true)) {
                    $this->flush();
                }
                $this->walk($child);
                if (in_array($tag, self::BLOCKS, true)) {
                    $this->flush();
                }
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $this->line .= ' '.$child->textContent;
            }
        }
    }

    private function row(DOMElement $tr): void
    {
        $cells = [];
        $nested = [];
        foreach ($tr->childNodes as $cell) {
            if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                continue;
            }
            $cells[] = $this->cellText($cell, $nested);
            for ($i = 1; $i < (int) $cell->getAttribute('colspan'); $i++) {
                $cells[] = '';
            }
        }
        if (collect($cells)->filter(fn ($c) => $c !== '')->isNotEmpty()) {
            $this->rows[] = $cells;
        }
        // A layout table inside a cell: its own rows follow the outer row.
        foreach ($nested as $table) {
            $this->walk($table);
        }
    }

    private function cellText(DOMNode $node, array &$nested): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'table') {
                    $nested[] = $child;

                    continue;
                }
                if (in_array($tag, ['style', 'script', 'img'], true)) {
                    continue;
                }
                $part = $this->cellText($child, $nested);
                $text .= in_array($tag, self::BLOCKS, true) ? "\n".$part."\n" : $part;
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->textContent;
            }
        }

        return trim(preg_replace(["/[ \t\r\x{00A0}]+/u", "/ *\n[\n ]*/"], [' ', "\n"], $text));
    }

    private function flush(): void
    {
        $text = trim(preg_replace("/[\s\x{00A0}]+/u", ' ', $this->line));
        if ($text !== '') {
            $this->rows[] = [$text];
        }
        $this->line = '';
    }

    /** @param array<int, array<int, string>> $rows */
    public static function fromRows(array $rows): string
    {
        $esc = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $sheet = '';
        $widths = [];
        foreach (array_values($rows) as $r => $cells) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach (array_values($cells) as $c => $value) {
                $value = (string) $value;
                $widths[$c] = max($widths[$c] ?? 8, min(60, mb_strlen($value) + 2));
                if ($value === '') {
                    continue;
                }
                $ref = self::column($c).($r + 1);
                if (preg_match('/^-?\d{1,15}(\.\d+)?$/', $value) && ! preg_match('/^0\d/', $value)) {
                    $sheet .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $sheet .= '<c r="'.$ref.'" t="inlineStr" s="1"><is><t xml:space="preserve">'.$esc($value).'</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $cols = '';
        foreach ($widths as $c => $w) {
            $cols .= '<col min="'.($c + 1).'" max="'.($c + 1).'" width="'.$w.'" customWidth="1"/>';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.($cols ? '<cols>'.$cols.'</cols>' : '').'<sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }
}
