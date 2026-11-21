<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use LastWord\Agent;
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
        5. If the request needs something this format cannot express (see Limits), call report_limit with the limit's id and the closest alternative's number. Do not write a draft that changes nothing and present it as the change. If part of the request can be done, draft that part as well.
        6. When you are done, end your turn with one short line. The person is told the outcome by the host; do not describe what you built.

        ## Limits

        These are what a last-word .docx cannot express. Each is from last-word's own documentation. Report one only when the request needs it.

        {$catalog->forPrompt()}

        ## The writer's guide (from particle-academy/last-word)

        TXT."\n\n".Agent::guide();
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
