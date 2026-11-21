<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Revisions;

use Closure;
use LastWord\Agent;
use ReflectionMethod;
use Throwable;

/**
 * The default TrackedChangesGuard: last-word's own signal where the installed
 * last-word has it, the archive scan always.
 *
 * last-word 0.22.0 counts revisions across every `word/*.xml` part, and
 * `Agent::read($bytes, ['refuseTrackedChanges' => true])` throws
 * `LastWord\Exceptions\TrackedChangesRefused` when there are any. This guard
 * detects that at RUNTIME, by the exception class, so the package needs no
 * constraint bump to use it: with last-word 0.21 the scan alone decides, with
 * 0.22 last-word's refusal (or its `trackedChanges.present` key) is a tracked
 * change too.
 *
 * The scan still runs after a clean read, and that is deliberate, not a
 * belt-and-braces habit: last-word's signal covers .docx and .dotx only, and
 * last-word also reads .odt, whose change regions only the scan sees. It is
 * also where the archive bounds live. So last-word's signal can only ADD a
 * refusal, never lift one, and the person hears the same fixed words either
 * way.
 */
final readonly class LastWordTrackedChanges implements TrackedChangesGuard
{
    /** Exists from last-word 0.22.0. */
    public const string REFUSED = 'LastWord\Exceptions\TrackedChangesRefused';

    /**
     * @param  ?Closure(string): mixed  $read  how last-word is asked; null asks `Agent::read` with `refuseTrackedChanges` (a test seam)
     * @param  string  $refused  the exception class that means "tracked changes"; its existence is what turns the signal on
     */
    public function __construct(
        private ScanForTrackedChanges $scan = new ScanForTrackedChanges,
        private ?Closure $read = null,
        private string $refused = self::REFUSED,
    ) {}

    /**
     * Whether the installed last-word reports tracked changes itself: its
     * refusal exception exists AND `Agent::read` takes options. Both, so a
     * last-word that has one without the other is never asked with an
     * option it would silently ignore.
     */
    public function supported(): bool
    {
        return $this->read !== null || (class_exists($this->refused) && new ReflectionMethod(Agent::class, 'read')->getNumberOfParameters() >= 2);
    }

    public function inspect(string $docx): Inspection
    {
        if ($this->supported()) {
            try {
                $read = $this->read !== null
                    ? ($this->read)($docx)
                    : new ReflectionMethod(Agent::class, 'read')->invoke(null, $docx, ['refuseTrackedChanges' => true]);
                if (is_array($read) && is_array($read['trackedChanges'] ?? null) && ($read['trackedChanges']['present'] ?? false) === true) {
                    return Inspection::tracked('trackedChanges', 'reported by last-word');
                }
            } catch (Throwable $e) {
                if (is_a($e, $this->refused)) {
                    return Inspection::tracked('trackedChanges', 'reported by last-word');
                }
                // Any other failure is the scan's to classify, and the
                // reader's to report when the edit reads the file.
            }
        }

        return $this->scan->inspect($docx);
    }
}
