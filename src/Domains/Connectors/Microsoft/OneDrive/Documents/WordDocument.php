<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Documents;

use Baka\Support\Str;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Kanvas\Connectors\Microsoft\OneDrive\Documents\Support\TemporaryFile;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use ZipArchive;

/**
 * Edits go straight to word/document.xml (never a PhpWord round-trip) so formatting the
 * caller did not touch survives byte for byte when the result overwrites the user's file.
 */
class WordDocument
{
    public const string WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const string XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';
    private const string DOCUMENT_ENTRY = 'word/document.xml';

    public static function readText(string $bytes): string
    {
        $document = self::loadDocument(self::readEntry($bytes));
        $xpath = self::xpath($document);

        $lines = [];
        foreach ($xpath->query('//w:p') as $paragraph) {
            $lines[] = self::paragraphText($xpath, $paragraph);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $replacements
     *
     * @return array{bytes: string, replaced: array<string, int>}
     */
    public static function replaceText(string $bytes, array $replacements): array
    {
        $document = self::loadDocument(self::readEntry($bytes));
        $xpath = self::xpath($document);
        $paragraphs = iterator_to_array($xpath->query('//w:p'));

        $replaced = [];
        foreach ($replacements as $search => $replacement) {
            $search = (string) $search;
            if ($search === '') {
                throw self::textNotFound($search);
            }

            $replaced[$search] = 0;
            foreach ($paragraphs as $paragraph) {
                $replaced[$search] += self::replaceInParagraph(
                    $xpath,
                    $paragraph,
                    $search,
                    Str::stripInvalidXmlChars((string) $replacement)
                );
            }
        }

        // Checked after all keys run, not before: an earlier key can consume a later key's only match.
        foreach ($replaced as $search => $count) {
            if ($count === 0) {
                throw self::textNotFound((string) $search);
            }
        }

        return [
            'bytes' => self::writeEntry($bytes, $document->saveXML()),
            'replaced' => $replaced,
        ];
    }

    /**
     * @param list<string> $paragraphs
     */
    public static function appendParagraphs(string $bytes, array $paragraphs): string
    {
        $document = self::loadDocument(self::readEntry($bytes));
        $body = self::xpath($document)->query('/w:document/w:body')->item(0);

        if (! $body instanceof DOMElement) {
            throw new OneDriveException(OneDriveErrorReasonEnum::UNSUPPORTED_TYPE, 'The Word document has no body.');
        }

        $sectionProperties = null;
        for ($child = $body->lastChild; $child !== null; $child = $child->previousSibling) {
            if ($child instanceof DOMElement) {
                $sectionProperties = $child->namespaceURI === self::WORD_NAMESPACE && $child->localName === 'sectPr' ? $child : null;

                break;
            }
        }

        foreach ($paragraphs as $text) {
            $body->insertBefore(self::buildParagraph($document, (string) $text), $sectionProperties);
        }

        return self::writeEntry($bytes, $document->saveXML());
    }

    /**
     * @param list<string> $paragraphs
     */
    public static function create(array $paragraphs): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        foreach ($paragraphs as $text) {
            $text = Str::stripInvalidXmlChars((string) $text);
            // PhpWord writes text raw unless output escaping is on, so "&" or "<" would corrupt the file.
            $section->addText(
                Settings::isOutputEscapingEnabled() ? $text : htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            );
        }

        return TemporaryFile::withBytes('', function (string $path) use ($phpWord) {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);

            return TemporaryFile::contents($path);
        });
    }

    private static function replaceInParagraph(
        DOMXPath $xpath,
        DOMElement $paragraph,
        string $search,
        string $replacement
    ): int {
        $count = 0;
        $from = 0;

        while (true) {
            $segments = self::segments($xpath, $paragraph);
            $text = implode('', array_column($segments, 'text'));

            $position = strpos($text, $search, $from);
            if ($position === false) {
                return $count;
            }

            $end = $position + strlen($search);
            $first = null;
            $last = null;
            foreach ($segments as $index => $segment) {
                if ($first === null && $position >= $segment['start'] && $position < $segment['end']) {
                    $first = $index;
                }
                if ($end - 1 >= $segment['start'] && $end - 1 < $segment['end']) {
                    $last = $index;

                    break;
                }
            }

            // Tabs and breaks are run content, not text: rewriting around them would move them.
            for ($index = $first; $index <= $last; $index++) {
                if ($segments[$index]['marker']) {
                    throw self::textNotFound($search);
                }
            }

            $firstSegment = $segments[$first];
            $lastSegment = $segments[$last];
            $prefix = substr($firstSegment['text'], 0, $position - $firstSegment['start']);
            $suffix = substr($lastSegment['text'], $end - $lastSegment['start']);

            if ($first === $last) {
                self::setText($firstSegment['node'], $prefix . $replacement . $suffix);
            } else {
                self::setText($firstSegment['node'], $prefix . $replacement);
                for ($index = $first + 1; $index < $last; $index++) {
                    self::setText($segments[$index]['node'], '');
                }
                self::setText($lastSegment['node'], $suffix);
            }

            $count++;
            $from = $position + strlen($replacement);
        }
    }

    private static function paragraphText(DOMXPath $xpath, DOMElement $paragraph): string
    {
        return implode('', array_column(self::segments($xpath, $paragraph), 'text'));
    }

    /**
     * The paragraph's run content in document order: w:t as its text, w:tab as "\t", w:br and w:cr
     * as "\n". Only children of w:r count (a w:tab under w:pPr/w:tabs is a tab-stop definition).
     * A paragraph can host nested paragraphs (text boxes); only nodes whose nearest w:p is this one
     * belong to it, or nested text would be read and replaced twice.
     *
     * @return list<array{node: DOMElement, text: string, marker: bool, start: int, end: int}>
     */
    private static function segments(DOMXPath $xpath, DOMElement $paragraph): array
    {
        $segments = [];
        $offset = 0;
        foreach ($xpath->query('.//w:r/w:t | .//w:r/w:tab | .//w:r/w:br | .//w:r/w:cr', $paragraph) as $node) {
            if (self::closestParagraph($node) !== $paragraph) {
                continue;
            }

            $marker = $node->localName !== 't';
            $text = match ($node->localName) {
                't' => $node->textContent,
                'tab' => "\t",
                default => "\n",
            };
            $segments[] = [
                'node' => $node,
                'text' => $text,
                'marker' => $marker,
                'start' => $offset,
                'end' => $offset + strlen($text),
            ];
            $offset += strlen($text);
        }

        return $segments;
    }

    private static function closestParagraph(DOMNode $node): ?DOMNode
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->namespaceURI === self::WORD_NAMESPACE && $parent->localName === 'p') {
                return $parent;
            }
        }

        return null;
    }

    private static function textNotFound(string $search): OneDriveException
    {
        return new OneDriveException(OneDriveErrorReasonEnum::TEXT_NOT_FOUND, 'Text not found: "' . $search . '"');
    }

    private static function setText(DOMElement $node, string $text): void
    {
        $node->textContent = $text;
        $node->setAttributeNS(self::XML_NAMESPACE, 'xml:space', 'preserve');
    }

    private static function buildParagraph(DOMDocument $document, string $text): DOMElement
    {
        $paragraph = $document->createElementNS(self::WORD_NAMESPACE, 'w:p');
        $run = $document->createElementNS(self::WORD_NAMESPACE, 'w:r');
        $textNode = $document->createElementNS(self::WORD_NAMESPACE, 'w:t');
        $textNode->setAttributeNS(self::XML_NAMESPACE, 'xml:space', 'preserve');
        $textNode->appendChild($document->createTextNode(Str::stripInvalidXmlChars($text)));
        $run->appendChild($textNode);
        $paragraph->appendChild($run);

        return $paragraph;
    }

    private static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NAMESPACE);

        return $xpath;
    }

    private static function loadDocument(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new OneDriveException(OneDriveErrorReasonEnum::UNSUPPORTED_TYPE, 'The Word document content is not valid XML.');
        }

        return $document;
    }

    private static function readEntry(string $bytes): string
    {
        return TemporaryFile::withBytes($bytes, function (string $path) {
            $zip = self::openArchive($path);
            $xml = $zip->getFromName(self::DOCUMENT_ENTRY);
            $zip->close();

            if ($xml === false) {
                throw self::notAWordDocument();
            }

            return $xml;
        });
    }

    /**
     * Replacing the one entry in a copy of the original archive leaves every other entry
     * (styles, numbering, media) with its original bytes.
     */
    private static function writeEntry(string $bytes, string $xml): string
    {
        return TemporaryFile::withBytes($bytes, function (string $path) use ($xml) {
            $zip = self::openArchive($path);
            $zip->addFromString(self::DOCUMENT_ENTRY, $xml);
            $zip->close();

            return TemporaryFile::contents($path);
        });
    }

    private static function openArchive(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw self::notAWordDocument();
        }

        return $zip;
    }

    private static function notAWordDocument(): OneDriveException
    {
        return new OneDriveException(OneDriveErrorReasonEnum::UNSUPPORTED_TYPE, 'The file is not a Word (.docx) document.');
    }
}
