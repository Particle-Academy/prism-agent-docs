<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use LastWord\Agent;
use Prism\AgentDocs\Check\Problem;
use Prism\AgentDocs\Limits\LimitCatalog;

/** The words the model is given: the mode's system prompt, and each edit's opening message. */
final class Prompt
{
    public static function system(LimitCatalog $catalog): string
    {
        return <<<TXT
        You are a Word-document specialist. You edit one .docx, or create one, for a person, through the tools you are given. You never produce a file any other way.

        ## How you work

        1. The message gives you the person's request and THE DOCUMENT TO REVISE, as last-word JSON. The host chose it, and it is your starting point, always; there is no other version.
        2. Change only what the request asks for. Call write_draft with the document as it should be.
           - Every top-level key you leave out is kept from the document to revise: `blocks`, `title`, `meta`.
           - Every `page` key you leave out is kept too: each band (`header`, `footer`, `firstHeader`, `firstFooter`, `evenHeader`, `evenFooter`), `size`, `orientation`, `background`, `border`, and each margin. A request about the header can send only `page.header`; a rewrite of the body that says nothing of the header must leave `page.header` out, and the header is kept.
           - To drop a band on purpose, list it in `remove`. Only when the request asks for that.
        3. Pictures are placed by name: `{"type": "image", "src": "image:NAME"}`. The message lists every picture in the document, wherever it is (body, header, footer). Never invent a name and never paste image data.
        4. write_draft re-opens the written file and checks it. If it answers with problems, fix exactly those and call it again. Never give up silently: if you cannot fix it, stop, and the person is told.
        5. If the request needs something this format cannot express (see Limits), call report_limit with the limit's id and the closest alternative's number. The alternative is OFFERED to the person, never applied: do not put it in a draft unless the request asks for exactly that change. Do not write a draft that changes nothing and present it as the change.
        6. A request can be PARTLY doable. Reporting a limit never ends the edit by itself: when a limit applies, still do the parts of the request this format can express, and report the limit as well. Each limit lists, under "Can still be done", the parts it can; when the request asks for one, put it in a write_draft call. "Use no margins for the header" in a document with a header is the example: the side margins cannot differ (report `side-margins-shared`), but the header distance can be zero (write `page.margins.header` as 0), and the side margins stay as they are.
        7. Use `page.header` and `page.footer` for anything every page should show. Use `firstHeader` / `firstFooter` only when the document already has one or the request asks for something on the first page only.
        8. When you are done, end your turn with one short line. The person is told the outcome by the host; do not describe what you built.

        ## Limits

        These are what a last-word .docx cannot express. Each is from last-word's own documentation. Report one only when the request needs it.

        {$catalog->forPrompt()}

        ## The writer's guide (from particle-academy/last-word)

        TXT."\n\n".Agent::guide();
    }

    /**
     * The message that returns a run to the model when it reported a limit
     * and skipped a part of the request the format can do. Only catalogue
     * words and fixed text: nothing from the document or the model.
     *
     * @param  list<Problem>  $problems
     */
    public static function pushback(array $problems): string
    {
        return implode("\n", [
            'CHECK FAILED: the edit is not finished. The limit you reported stays reported, and its alternative stays offered, not applied. But the request also asks for something this format CAN do, and what you would hand over does not do it:',
            json_encode(array_map(static fn (Problem $p): array => $p->toArray(), $problems), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'Call write_draft with that change, starting from the document to revise, then end your turn with one short line.',
        ]);
    }

    public static function opening(string $request, Workspace $workspace): string
    {
        $source = $workspace->source;
        $pictures = [];
        foreach ($source->pictures as $picture) {
            $pictures[] = sprintf('- image:%s in %s (%s, %d bytes)', $picture->name, $picture->part === '/blocks' ? 'the body' : $picture->part, $picture->type, $picture->bytes);
        }

        $document = json_encode($source->named, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $state = $source->exists() ? 'THE DOCUMENT TO REVISE' : 'THERE IS NO DOCUMENT YET: you are creating one. It starts as';

        $lines = [
            'REQUEST',
            $request,
            '',
            $state.' (last-word JSON; pictures by name)',
            $document,
            '',
            'PICTURES IN THE DOCUMENT',
            $pictures === [] ? '- none' : implode("\n", $pictures),
        ];

        $offered = array_keys($workspace->options->images);
        if ($offered !== []) {
            $lines[] = '';
            $lines[] = 'PICTURES THE HOST OFFERS (place one only when the request asks for it)';
            foreach ($offered as $name) {
                $lines[] = '- image:'.$name;
            }
        }

        if ($source->unrepresented !== []) {
            $lines[] = '';
            $lines[] = 'NOT CARRIED BY THE WRITER (in the file, but last-word cannot hold it; it will not be in the draft)';
            $lines[] = json_encode($source->unrepresented, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines);
    }
}
