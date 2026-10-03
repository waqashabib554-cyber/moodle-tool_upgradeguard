<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A single thing a check noticed about a plugin or about the site.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use JsonException;
use moodle_url;

/**
 * One finding produced by a check.
 *
 * Findings are immutable: a check returns findings, nothing mutates them
 * afterwards. Everything needed to explain the finding to an administrator is
 * kept here, so that rendering never has to re-run a check.
 */
final class finding {
    /** @var string The key of the check that produced this finding. */
    public readonly string $checkkey;

    /** @var severity How serious the finding is. */
    public readonly severity $severity;

    /** @var string Language string key explaining what was found. */
    public readonly string $messagekey;

    /** @var array Parameters for both the message and the action string. */
    public readonly array $params;

    /** @var confidence How trustworthy this finding is. */
    public readonly confidence $confidence;

    /** @var bool Whether updating the plugin is expected to fix this. */
    public readonly bool $fixedbyupdate;

    /** @var string Language string key of the suggested next step. */
    public readonly string $actionkey;

    /** @var string|null Optional URL with more information. */
    public readonly ?string $docsurl;

    /**
     * Create a finding.
     *
     * @param string $checkkey The key of the check that produced this finding.
     * @param severity $severity How serious the finding is.
     * @param string $messagekey Language string key explaining what was found.
     * @param array $params Parameters for both the message and the action string.
     * @param confidence $confidence How trustworthy this finding is.
     * @param bool $fixedbyupdate Whether updating the plugin is expected to fix this.
     * @param string $actionkey Language string key of the suggested next step.
     * @param string|null $docsurl Optional URL with more information.
     */
    public function __construct(
        string $checkkey,
        severity $severity,
        string $messagekey,
        array $params = [],
        confidence $confidence = confidence::medium,
        bool $fixedbyupdate = false,
        string $actionkey = 'action_none',
        ?string $docsurl = null,
    ) {
        $this->checkkey = $checkkey;
        $this->severity = $severity;
        $this->messagekey = $messagekey;
        $this->params = $params;
        $this->confidence = $confidence;
        $this->fixedbyupdate = $fixedbyupdate;
        $this->actionkey = $actionkey;
        $this->docsurl = $docsurl;
    }

    /**
     * The translated message for this finding.
     *
     * @return string
     */
    public function get_message(): string {
        return get_string($this->messagekey, 'tool_upgradeguard', $this->params);
    }

    /**
     * The translated suggestion for what to do about this finding.
     *
     * @return string
     */
    public function get_action(): string {
        if ($this->actionkey === '') {
            return get_string('action_none', 'tool_upgradeguard');
        }
        return get_string($this->actionkey, 'tool_upgradeguard', $this->params);
    }

    /**
     * The core administration page that can carry out this action, when one exists.
     *
     * This deliberately contains no new Upgrade Guard workflow. It only gives the
     * administrator a direct route to Moodle's existing plugin management pages.
     *
     * @return string|null Absolute URL, or null when the action has no direct core route.
     */
    public function get_action_url(): ?string {
        return match ($this->actionkey) {
            'action_install_dependency',
            'action_update_plugin' => (new moodle_url('/admin/tool/installaddon/index.php'))->out(false),
            'action_review_or_remove' => (new moodle_url('/admin/plugins.php'))->out(false),
            default => null,
        };
    }

    /**
     * Convert this finding into a database record.
     *
     * @param int $scanid The scan this finding belongs to.
     * @param int $pluginid The scanned plugin, or 0 for a site wide finding.
     * @return \stdClass
     */
    public function to_record(int $scanid, int $pluginid): \stdClass {
        return (object) [
            'scanid' => $scanid,
            'pluginid' => $pluginid,
            'checkkey' => $this->checkkey,
            'severity' => $this->severity->value,
            'messagekey' => $this->messagekey,
            'params' => $this->encode_params(),
            'confidence' => $this->confidence->value,
            'fixedbyupdate' => $this->fixedbyupdate ? 1 : 0,
            'actionkey' => $this->actionkey,
            'docsurl' => $this->docsurl,
            'timecreated' => time(),
        ];
    }

    /**
     * Recreate a finding from a database record.
     *
     * @param \stdClass $record A record from tool_upgradeguard_finding.
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        $params = [];
        if (!empty($record->params)) {
            $decoded = json_decode($record->params, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        return new self(
            checkkey: $record->checkkey,
            severity: severity::from($record->severity),
            messagekey: $record->messagekey,
            params: $params,
            confidence: confidence::from($record->confidence),
            fixedbyupdate: !empty($record->fixedbyupdate),
            actionkey: $record->actionkey,
            docsurl: $record->docsurl ?: null,
        );
    }

    /**
     * Sort findings so that the most worrying ones come first.
     *
     * Ties are broken by confidence (high confidence first) and finally by the
     * check key, so that the order is stable between scans.
     *
     * @param finding $a First finding.
     * @param finding $b Second finding.
     * @return int
     */
    public static function compare(self $a, self $b): int {
        if ($a->severity->rank() !== $b->severity->rank()) {
            return $b->severity->rank() <=> $a->severity->rank();
        }
        if ($a->confidence->rank() !== $b->confidence->rank()) {
            return $b->confidence->rank() <=> $a->confidence->rank();
        }
        return strcmp($a->checkkey, $b->checkkey);
    }

    /**
     * JSON encode the message parameters, never throwing at runtime.
     *
     * @return string|null
     */
    private function encode_params(): ?string {
        if (empty($this->params)) {
            return null;
        }

        try {
            return json_encode($this->params, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            debugging('tool_upgradeguard: could not encode finding parameters for ' . $this->checkkey, DEBUG_DEVELOPER);
            return null;
        }
    }
}
