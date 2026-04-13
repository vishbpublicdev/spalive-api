<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller\Data;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;

/**
 * Centralizes "neuro Level 3" access: legacy L3 medical / OT, allowlist, and post-cutoff Level 2 presencial (cat_trainings).
 */
final class NeuroLevel3AccessHelper
{
    private static function policyCutoff(): ?\DateTimeImmutable
    {
        $cutoff = Configure::read('NeuroLevel3Access.cutoff');
        if ($cutoff === null || $cutoff === '') {
            return null;
        }
        if ($cutoff instanceof \DateTimeImmutable) {
            return $cutoff;
        }
        if ($cutoff instanceof \DateTimeInterface) {
            return new \DateTimeImmutable($cutoff->format('Y-m-d H:i:s'));
        }
        if (!is_string($cutoff)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($cutoff);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @return int[]
     */
    private static function allowlistUserIds(): array
    {
        $ids = Configure::read('NeuroLevel3Access.allowlist_user_ids', []);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Same criteria as historical check_training_medical: LEVEL 3 MEDICAL attended, or OT LEVEL3_NEUROTOXINS attended.
     */
    public static function userHasLegacyLevel3MedicalOrOt(int $userId): bool
    {
        $DataTrainings = TableRegistry::getTableLocator()->get('SpaLiveV1.DataTrainings');

        $entTraining = $DataTrainings->find()
            ->join([
                'CatTrainings' => [
                    'table' => 'cat_trainings',
                    'type' => 'INNER',
                    'conditions' => 'CatTrainings.id = DataTrainings.training_id',
                ],
            ])
            ->where([
                'DataTrainings.user_id' => $userId,
                'DataTrainings.deleted' => 0,
                'DataTrainings.attended' => 1,
                'CatTrainings.level' => 'LEVEL 3 MEDICAL',
                'CatTrainings.deleted' => 0,
            ])
            ->first();

        if ($entTraining !== null) {
            return true;
        }

        $userCourse = $DataTrainings->find()
            ->join([
                'CatTrainings' => [
                    'table' => 'cat_trainings',
                    'type' => 'INNER',
                    'conditions' => 'CatTrainings.id = DataTrainings.training_id',
                ],
                'CTC' => [
                    'table' => 'cat_courses_type',
                    'type' => 'INNER',
                    'conditions' => 'CTC.name_key = CatTrainings.level',
                ],
                'DCC' => [
                    'table' => 'data_coverage_courses',
                    'type' => 'INNER',
                    'conditions' => 'DCC.course_type_id = CTC.id',
                ],
                'STOT' => [
                    'table' => 'sys_treatments_ot',
                    'type' => 'INNER',
                    'conditions' => 'STOT.id = DCC.ot_id',
                ],
            ])
            ->where([
                'DataTrainings.user_id' => $userId,
                'DataTrainings.deleted' => 0,
                'DataTrainings.attended' => 1,
                'CatTrainings.deleted' => 0,
                'STOT.name_key' => 'LEVEL3_NEUROTOXINS',
            ])
            ->first();

        return $userCourse !== null;
    }

    /**
     * Level 2 (or OT advanced neuro) completed per get_courses_user rules, with cat_trainings.scheduled on or after cutoff.
     * Does not use data_courses / external schools alone.
     */
    public static function userHasAdvancedNeuroPresencialOnOrAfterCutoff(int $userId, \DateTimeInterface $cutoff): bool
    {
        $DataTrainings = TableRegistry::getTableLocator()->get('SpaLiveV1.DataTrainings');
        $now = date('Y-m-d H:i:s');
        $changeThreshold = '2023-02-27';

        $level2Rows = $DataTrainings->find()
            ->select([
                'attended' => 'DataTrainings.attended',
                'scheduled' => 'CatTrainings.scheduled',
                'created' => 'CatTrainings.created',
            ])
            ->join([
                'CatTrainings' => [
                    'table' => 'cat_trainings',
                    'type' => 'INNER',
                    'conditions' => 'CatTrainings.id = DataTrainings.training_id',
                ],
            ])
            ->where([
                'DataTrainings.user_id' => $userId,
                'DataTrainings.deleted' => 0,
                'CatTrainings.deleted' => 0,
                'CatTrainings.level' => 'LEVEL 2',
            ])
            ->all();

        foreach ($level2Rows as $row) {
            if (self::trainingRowIsDone($row, $now, $changeThreshold)
                && self::scheduledOnOrAfterCutoff(self::rowScheduled($row), $cutoff)
            ) {
                return true;
            }
        }

        $otAdvancedRows = $DataTrainings->find()
            ->select([
                'attended' => 'DataTrainings.attended',
                'scheduled' => 'CatTrainings.scheduled',
                'created' => 'CatTrainings.created',
            ])
            ->join([
                'CatTrainings' => [
                    'table' => 'cat_trainings',
                    'type' => 'INNER',
                    'conditions' => 'CatTrainings.id = DataTrainings.training_id',
                ],
                'CTC' => [
                    'table' => 'cat_courses_type',
                    'type' => 'INNER',
                    'conditions' => 'CTC.name_key = CatTrainings.level',
                ],
                'DCC' => [
                    'table' => 'data_coverage_courses',
                    'type' => 'INNER',
                    'conditions' => 'DCC.course_type_id = CTC.id',
                ],
                'STOT' => [
                    'table' => 'sys_treatments_ot',
                    'type' => 'INNER',
                    'conditions' => 'STOT.id = DCC.ot_id',
                ],
            ])
            ->where([
                'DataTrainings.user_id' => $userId,
                'DataTrainings.deleted' => 0,
                'CatTrainings.deleted' => 0,
                'STOT.name_key' => 'ADVANCED_NEUROTOXINS',
            ])
            ->all();

        foreach ($otAdvancedRows as $row) {
            if (self::trainingRowIsDone($row, $now, $changeThreshold)
                && self::scheduledOnOrAfterCutoff(self::rowScheduled($row), $cutoff)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Cake\Datasource\EntityInterface|array $row
     */
    private static function rowScheduled($row)
    {
        if (is_array($row)) {
            return $row['scheduled'] ?? null;
        }

        return $row->get('scheduled');
    }

    /**
     * @param mixed $value Cake Chronos, \DateTimeInterface, or datetime string
     */
    private static function toDateYmd($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            try {
                return (new \DateTimeImmutable($value))->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param mixed $value Cake Chronos, \DateTimeInterface, or datetime string
     */
    private static function toDateTimeStr($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value)) {
            try {
                return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param \Cake\Datasource\EntityInterface|array $row
     */
    private static function trainingRowIsDone($row, string $now, string $changeThreshold): bool
    {
        $created = is_array($row) ? ($row['created'] ?? null) : $row->get('created');
        $scheduled = is_array($row) ? ($row['scheduled'] ?? null) : $row->get('scheduled');
        $attended = (int) (is_array($row) ? ($row['attended'] ?? 0) : $row->get('attended'));

        $createdStr = self::toDateYmd($created);
        $scheduledStr = self::toDateTimeStr($scheduled);
        if ($createdStr === null || $scheduledStr === null) {
            return false;
        }

        $isBeforeChange = $changeThreshold > $createdStr;
        if ($isBeforeChange) {
            return $scheduledStr < $now;
        }

        return $attended === 1;
    }

    /**
     * @param mixed $scheduled Cake FrozenTime or similar
     */
    private static function scheduledOnOrAfterCutoff($scheduled, \DateTimeInterface $cutoff): bool
    {
        if ($scheduled === null) {
            return false;
        }
        if ($scheduled instanceof \DateTimeInterface) {
            $scheduledDt = $scheduled instanceof \DateTimeImmutable
                ? $scheduled
                : new \DateTimeImmutable($scheduled->format('Y-m-d H:i:s'));
        } else {
            try {
                $scheduledDt = new \DateTimeImmutable((string) $scheduled);
            } catch (\Exception $e) {
                return false;
            }
        }

        return $scheduledDt >= $cutoff;
    }

    public static function userHasNeuroLevel3Access(int $userId): bool
    {
        if (in_array($userId, self::allowlistUserIds(), true)) {
            return true;
        }
        if (self::userHasLegacyLevel3MedicalOrOt($userId)) {
            return true;
        }
        $cutoff = self::policyCutoff();
        if ($cutoff === null) {
            return false;
        }

        return self::userHasAdvancedNeuroPresencialOnOrAfterCutoff($userId, $cutoff);
    }
}
