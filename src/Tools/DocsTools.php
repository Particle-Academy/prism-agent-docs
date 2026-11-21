<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Tools;

use LastWord\Agent;
use Prism\AgentDocs\Support\DocumentTree;
use Prism\AgentDocs\Workspace;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;

/**
 * The model's tools. Each is a thin wrapper: the rules live in Workspace, so
 * the tools add nothing a test of the workspace would miss.
 *
 * There is no tool that reads another version of the document and none
 * that writes the saved file. What the model can still invoke is: write a checked draft,
 * report a catalogued limit, read the schema.
 */
final class DocsTools
{
    public const string WRITE_DRAFT = 'write_draft';

    public const string REPORT_LIMIT = 'report_limit';

    public const string DOCUMENT_SCHEMA = 'document_schema';

    public const array ALL = [self::WRITE_DRAFT, self::REPORT_LIMIT, self::DOCUMENT_SCHEMA];

    public static function writeDraft(Workspace $workspace): Tool
    {
        return (new Tool)
            ->as(self::WRITE_DRAFT)
            ->for('Write the document as it should be, as last-word JSON. Keys you leave out are kept from the document you were given (body, title, each page band, each margin). The written file is re-opened and checked; problems come back for you to fix.')
            ->withParameter(new RawSchema('document', [
                'type' => 'object',
                'description' => 'A last-word document: {"blocks": [...], "page": {...}, "title": "..."}. Omit what you are not changing. Pictures by name: {"type": "image", "src": "image:NAME"}.',
                'additionalProperties' => true,
            ]))
            ->withArrayParameter('remove', 'Bands to drop on purpose, only when the request asks for that.', new EnumSchema('band', 'A page band.', DocumentTree::BANDS), required: false)
            ->using(static function (mixed ...$arguments) use ($workspace): string {
                $remove = is_array($arguments['remove'] ?? null) ? array_values(array_filter($arguments['remove'], is_string(...))) : [];

                return self::json($workspace->writeDraft($arguments['document'] ?? null, $remove));
            });
    }

    public static function reportLimit(Workspace $workspace): Tool
    {
        return (new Tool)
            ->as(self::REPORT_LIMIT)
            ->for('Report that the request needs something a last-word .docx cannot express. The person is told the limit and the alternative you choose.')
            ->withEnumParameter('limit', 'The limit\'s id, from the Limits list.', $workspace->catalog->ids())
            ->withParameter(new NumberSchema('alternative', 'The number of the closest alternative in that limit\'s list; 0 is the closest.'), required: false)
            ->using(static function (mixed ...$arguments) use ($workspace): string {
                $id = $arguments['limit'] ?? null;
                $alternative = $arguments['alternative'] ?? 0;

                return self::json($workspace->reportLimit(is_string($id) ? $id : '', is_numeric($alternative) ? (int) $alternative : 0));
            });
    }

    public static function documentSchema(Workspace $workspace): Tool
    {
        return (new Tool)
            ->as(self::DOCUMENT_SCHEMA)
            ->for('The JSON Schema of a last-word document, with the picture names this document may use. Read it when you are unsure of a block\'s shape.')
            ->using(static fn (): string => self::json(Agent::jsonSchema(['images' => $workspace->writerOptions()['images'], 'imageLimits' => $workspace->writerOptions()['imageLimits']])));
    }

    /** @return list<Tool> */
    public static function all(Workspace $workspace): array
    {
        return [self::writeDraft($workspace), self::reportLimit($workspace), self::documentSchema($workspace)];
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
