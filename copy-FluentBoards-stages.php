<?php
/**
 * Plugin Snippet: Extend FluentBoards stage duplication
 * Author: khvichadev
 * Description: Enhances the copyStagesOfBoard method to include visual settings (color, bg_color) and default assignees when duplicating a board.
 */

add_action('plugins_loaded', function () {
    if (!class_exists('\FluentBoards\App\Services\StageService')) {
        return;
    }

    // Prevent re-declaration
    if (!class_exists('Khvichadev_Custom_StageService')) {

        class Khvichadev_Custom_StageService extends \FluentBoards\App\Services\StageService
        {
            /**
             * Copy stages from one board to another, including color, background color, and default assignees.
             *
             * @param object $board          The new board object
             * @param int    $fromBoardId    Source board ID
             * @param string $isWithTemplates Whether to include templates
             * @return array Stage ID map
             */
            public function copyStagesOfBoard($board, $fromBoardId, $isWithTemplates = 'no')
            {
                $stages = \FluentBoards\App\Models\Stage::where('board_id', $fromBoardId)
                    ->where('type', 'stage')
                    ->whereNull('archived_at')
                    ->get();

                $stageMapForCopyingTask = [];

                foreach ($stages as $key => $stage) {
                    $stageToSave = [
                        'title'     => $stage['title'],
                        'board_id'  => $board->id,
                        'slug'      => str_replace(' ', '-', strtolower($stage['title'])),
                        'type'      => 'stage',
                        'position'  => $key + 1
                    ];

                    // Base settings
                    $settings = [
                        'default_task_status' => $stage->settings['default_task_status'] ?? 'open'
                    ];

                    // Include template flag if requested
                    if (!empty($stage->settings['is_template']) && $isWithTemplates === 'yes') {
                        $settings['is_template'] = $stage->settings['is_template'];
                    }

                    // ✅ Include default assignees if present
                    if (!empty($stage->settings['default_task_assignees'])) {
                        $settings['default_task_assignees'] = $stage->settings['default_task_assignees'];
                    }

                    $stageToSave['settings'] = $settings;

                    // ✅ Include color and background color
                    if (!empty($stage->color)) {
                        $stageToSave['color'] = $stage->color;
                    }

                    if (!empty($stage->bg_color)) {
                        $stageToSave['bg_color'] = $stage->bg_color;
                    }

                    // Create new stage
                    $newStage = \FluentBoards\App\Models\Stage::create($stageToSave);

                    // Map original stage ID to new stage ID (used for tasks if needed)
                    $stageMapForCopyingTask[$stage['id']] = $newStage->id;
                }

                return $stageMapForCopyingTask;
            }
        }

        // Bind custom service to FluentBoards container
        add_action('init', function () {
            if (function_exists('fluentBoards')) {
                fluentBoards()->bind(
                    \FluentBoards\App\Services\StageService::class,
                    Khvichadev_Custom_StageService::class
                );
            }
        }, 20);
    }
});
