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
 * Turns stored plugin statuses into a readiness score.
 *
 * @package    tool_upgradeguard
 * @copyright  2026 Waqas Habib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_upgradeguard\local;

use stdClass;

/**
 * Calculates the Section 6.5 status, type, and usage score.
 *
 * Findings are intentionally not scored directly. A low-confidence
 * informational finding, for example an available update, cannot therefore
 * create a phantom Unknown penalty. Only the resolved status of each plugin
 * contributes to the score.
 */
final class score_calculator {
    /** @var int The score when there is no plugin penalty. */
    private const MAX_SCORE = 100;

    /** @var array Default base penalties by plugin status. */
    private const BASE_PENALTIES = [
        'blocker' => 12.0,
        'caution' => 4.0,
        'unknown' => 3.0,
        'update' => 1.0,
        'ready' => 0.0,
    ];

    /** @var array Default penalties by site-wide finding severity. */
    private const ENVIRONMENT_PENALTIES = [
        'blocker' => 25.0,
        'caution' => 5.0,
        'info' => 0.0,
    ];

    /** @var array Default type weights. */
    private const TYPE_WEIGHTS = [
        'theme' => 3.0,
        'auth' => 3.0,
        'enrol' => 2.0,
        'mod' => 2.0,
        'local' => 2.0,
        'editor' => 2.0,
        'other' => 1.0,
    ];

    /** @var array Default multipliers keyed by usage state. */
    private const USAGE_MULTIPLIERS = [
        'used' => 1.5,
        'unused' => 0.3,
        'unknown' => 1.0,
    ];

    /**
     * Calculate the rounded readiness score.
     *
     * @param stdClass[] $plugins Stored plugin records.
     * @param finding[] $sitefindings Site-wide findings.
     * @return int Score from 0 to 100.
     */
    public function calculate(array $plugins, array $sitefindings = []): int {
        $penalty = array_sum(array_column($this->get_breakdown($plugins, $sitefindings), 'penalty'));
        return max(0, min(self::MAX_SCORE, (int) round(self::MAX_SCORE - $penalty)));
    }

    /**
     * Calculate the score after excluding measured-unused plugins.
     *
     * This is a planning estimate only. It does not imply a plugin is safe to
     * remove, because usage counts cannot prove the absence of custom code or
     * external integrations.
     *
     * @param stdClass[] $plugins Stored plugin records.
     * @param finding[] $sitefindings Site-wide findings.
     * @return int Score from 0 to 100.
     */
    public function calculate_without_unused(array $plugins, array $sitefindings = []): int {
        $remaining = array_filter($plugins, static function (stdClass $plugin): bool {
            return $plugin->usagecount === null || (int) $plugin->usagecount > 0;
        });

        return $this->calculate($remaining, $sitefindings);
    }

    /**
     * Explain every plugin and site-wide contribution, worst first.
     *
     * @param stdClass[] $plugins Stored plugin records.
     * @param finding[] $sitefindings Site-wide findings.
     * @return array[] Entries with component, status, usage, weights, and penalty.
     */
    public function get_breakdown(array $plugins, array $sitefindings = []): array {
        $breakdown = [];

        foreach ($plugins as $plugin) {
            $status = status::tryFrom((string) $plugin->status) ?? status::unknown;
            $usagestate = $this->get_usage_state($plugin);
            $basepenalty = $this->get_base_penalty($status);
            $typeweight = $this->get_type_weight((string) $plugin->plugintype);
            $usagemultiplier = $this->get_usage_multiplier($usagestate);

            $breakdown[] = [
                'component' => (string) $plugin->component,
                'status' => $status,
                'usage' => $usagestate,
                'basepenalty' => $basepenalty,
                'typeweight' => $typeweight,
                'usagemultiplier' => $usagemultiplier,
                'penalty' => round($basepenalty * $typeweight * $usagemultiplier, 2),
            ];
        }

        foreach ($sitefindings as $finding) {
            $basepenalty = self::ENVIRONMENT_PENALTIES[$finding->severity->value];
            $breakdown[] = [
                'component' => 'site',
                'status' => $finding->severity,
                'usage' => 'site',
                'basepenalty' => $basepenalty,
                'typeweight' => 1.0,
                'usagemultiplier' => 1.0,
                'penalty' => $basepenalty,
            ];
        }

        usort($breakdown, static function (array $a, array $b): int {
            if ($a['penalty'] !== $b['penalty']) {
                return $b['penalty'] <=> $a['penalty'];
            }
            return $a['component'] <=> $b['component'];
        });

        return $breakdown;
    }

    /**
     * Count statuses and verdict inputs from plugin rows.
     *
     * @param stdClass[] $plugins Stored plugin records.
     * @return array Counts and booleans needed for the verdict.
     */
    public function count_statuses(array $plugins): array {
        $counts = [
            'blockers' => 0,
            'cautions' => 0,
            'unknowns' => 0,
            'hasusedblocker' => false,
        ];

        foreach ($plugins as $plugin) {
            $status = status::tryFrom((string) $plugin->status) ?? status::unknown;
            if ($status === status::blocker) {
                $counts['blockers']++;
                $counts['hasusedblocker'] = $counts['hasusedblocker'] || $this->get_usage_state($plugin) === 'used';
            } else if ($status === status::caution) {
                $counts['cautions']++;
            } else if ($status === status::unknown) {
                $counts['unknowns']++;
            }
        }

        return $counts;
    }

    /**
     * Count the site-wide findings that affect the stored summary.
     *
     * Site-wide findings have no plugin row, so they are counted separately and
     * are never inferred from the plugin statuses.
     *
     * @param finding[] $sitefindings Site-wide findings.
     * @return array Environment counts for the scan summary.
     */
    public function count_environment(array $sitefindings): array {
        $counts = [
            'environmentblockers' => 0,
            'environmentcautions' => 0,
        ];

        foreach ($sitefindings as $finding) {
            if ($finding->severity === severity::blocker) {
                $counts['environmentblockers']++;
            } else if ($finding->severity === severity::caution) {
                $counts['environmentcautions']++;
            }
        }

        return $counts;
    }

    /**
     * Resolve the configured base penalty for a status.
     *
     * @param status $status Plugin status.
     * @return float Non-negative base penalty.
     */
    private function get_base_penalty(status $status): float {
        return $this->get_weight('weight' . $status->value, self::BASE_PENALTIES[$status->value]);
    }

    /**
     * Resolve the configured type weight.
     *
     * @param string $type Moodle plugin type.
     * @return float Non-negative type weight.
     */
    private function get_type_weight(string $type): float {
        $key = array_key_exists($type, self::TYPE_WEIGHTS) ? $type : 'other';
        return $this->get_weight('weight' . $key, self::TYPE_WEIGHTS[$key]);
    }

    /**
     * Resolve one stored plugin's measurable usage state.
     *
     * @param stdClass $plugin Stored plugin record.
     * @return string used, unused, or unknown.
     */
    private function get_usage_state(stdClass $plugin): string {
        if ($plugin->usagecount === null) {
            return 'unknown';
        }
        return (int) $plugin->usagecount > 0 ? 'used' : 'unused';
    }

    /**
     * Resolve the configured multiplier for one usage state.
     *
     * @param string $state Usage state.
     * @return float Non-negative multiplier.
     */
    private function get_usage_multiplier(string $state): float {
        return $this->get_weight('usagemultiplier' . $state, self::USAGE_MULTIPLIERS[$state]);
    }

    /**
     * Read one non-negative numeric setting.
     *
     * @param string $name Setting name.
     * @param float $default Shipped default.
     * @return float Configured or default value.
     */
    private function get_weight(string $name, float $default): float {
        $configured = get_config('tool_upgradeguard', $name);
        if ($configured === false || $configured === null || $configured === '') {
            return $default;
        }
        return max(0.0, (float) $configured);
    }
}
