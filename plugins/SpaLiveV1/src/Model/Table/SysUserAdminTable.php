<?php
namespace SpaLiveV1\Model\Table;

use Cake\ORM\Table;

class SysUserAdminTable extends Table
{
    /**
     * Config key used to route FILLERS contexts to a dedicated medical director.
     * It must contain a sys_users_admin.id for an active DOCTOR user.
     */
    private const FILLERS_DOCTOR_ENV_KEY = 'FILLERS_MD_ADMIN_ID';

    public function initialize(array $config) : void
    {
        $this->setTable('sys_users_admin'); // Name of the table in the database, if absent convention assumes lowercase version of file prefix

        $this->addBehavior('SpaLiveV1.My');
        $this->addBehavior('Timestamp'); // Allows your model to timestamp records on creation/modification
    }

    /**
     * All active DOCTOR admin ids (sys_users_admin).
     *
     * @return list<int>
     */
    private function eligibleDoctorAdminIds(): array
    {
        $doctorRows = $this->find()
            ->select(['id'])
            ->where(['SysUserAdmin.user_type' => 'DOCTOR', 'SysUserAdmin.deleted' => 0])
            ->enableHydration(false)
            ->toArray();
        $doctorIds = array_map('intval', array_column($doctorRows, 'id'));

        return array_values(array_filter($doctorIds, static fn ($id) => $id > 0));
    }

    /**
     * Random pick among all active DOCTORs (uniform; no historical count weighting).
     *
     * @return int Admin doctor id, or 0 if none
     */
    private function pickRandomEligibleDoctorId(): int
    {
        $doctorIds = $this->eligibleDoctorAdminIds();
        if ($doctorIds === []) {
            return 0;
        }

        return (int)$doctorIds[array_rand($doctorIds)];
    }

    /**
     * Whether this sys_users_admin row is an active eligible medical director assignee.
     */
    private function isEligibleDoctorAdmin(int $adminId): bool
    {
        if ($adminId <= 0) {
            return false;
        }

        return $this->find()
                ->where([
                    'SysUserAdmin.id' => $adminId,
                    'SysUserAdmin.user_type' => 'DOCTOR',
                    'SysUserAdmin.deleted' => 0,
                ])
                ->count() > 0;
    }

    /**
     * Random active DOCTOR id for new injector md_id assignments (same distribution as getAssignedDoctorInjector).
     */
    public function pickBalancedDoctorIdForNewAssignment(): int
    {
        return $this->pickRandomEligibleDoctorId();
    }

    /**
     * When $injector_id > 0, ensures that user has an md_id (persists if missing).
     * When $injector_id === 0, returns a random doctor id without updating any user row.
     */
    public function getRandomDoctor($injector_id = 0): int
    {
        $injectorId = (int)$injector_id;
        if ($injectorId > 0) {
            return $this->getAssignedDoctorInjector($injectorId);
        }

        return $this->pickRandomEligibleDoctorId();
    }

    public function getAssignedDoctor(): int
    {
        $last = $this->getConnection()->query(
            "SELECT U.md_id FROM sys_users U WHERE U.deleted = 0 AND U.md_id != 0 AND type <> 'injector' ORDER BY id DESC LIMIT 1"
        )->fetchAll('assoc');
        $last_d = 0;
        if (!empty($last[0]['md_id'])) {
            $last_d = (int)$last[0]['md_id'];
        }

        $baseWhere = [
            'SysUserAdmin.user_type' => 'DOCTOR',
            'SysUserAdmin.deleted' => 0,
        ];

        if ($last_d === 0) {
            $doctors = $this->find()->select(['SysUserAdmin.id'])
                ->where($baseWhere)
                ->toArray();
            $numDocts = count($doctors);
            if ($numDocts === 0) {
                return $this->pickRandomEligibleDoctorId();
            }
            $pos = rand(0, $numDocts - 1);

            return (int)$doctors[$pos]['id'];
        }

        $whereAlt = $baseWhere + ['SysUserAdmin.id <>' => $last_d];
        $doctors = $this->find()->select(['SysUserAdmin.id'])
            ->where($whereAlt)
            ->toArray();
        $numDocts = count($doctors);
        if ($numDocts === 0) {
            return $this->pickRandomEligibleDoctorId();
        }
        $pos = rand(0, $numDocts - 1);

        return (int)$doctors[$pos]['id'];
    }

    /**
     * Returns sys_users.md_id for this user when it points to an active DOCTOR admin; otherwise assigns
     * a random active DOCTOR (including replacing stale/non-DOCTOR/deleted admins), persists to sys_users, and returns it.
     * Used for injectors (e.g. treatments: same value as data_treatment.assigned_doctor via getRandomDoctor).
     *
     * @param int $id sys_users.id
     * @return int sys_users_admin id of assigned doctor, or 0 if user missing or no doctors available
     */
    public function getAssignedDoctorInjector(int $id): int
    {
        $id = (int)$id;
        if ($id <= 0) {
            return 0;
        }

        $stmt = $this->getConnection()->execute(
            'SELECT id, md_id FROM sys_users WHERE id = ? AND deleted = 0 LIMIT 1',
            [$id]
        );
        $row = $stmt->fetch('assoc');
        if ($row === false) {
            return 0;
        }

        $storedMd = (int)$row['md_id'];
        if ($storedMd > 0 && $this->isEligibleDoctorAdmin($storedMd)) {
            return $storedMd;
        }

        $newMd = $this->pickRandomEligibleDoctorId();
        if ($newMd <= 0) {
            return 0;
        }

        // Unassigned: only set when still zero (avoids overwriting a concurrent assignment).
        // Stale md_id: replace only while row still holds the same stored value (avoids clobbering a concurrent fix).
        if ($storedMd <= 0) {
            $upd = $this->getConnection()->execute(
                'UPDATE sys_users SET md_id = ? WHERE id = ? AND deleted = 0 AND IFNULL(md_id, 0) = 0',
                [$newMd, $id]
            );
        } else {
            $upd = $this->getConnection()->execute(
                'UPDATE sys_users SET md_id = ? WHERE id = ? AND deleted = 0 AND md_id = ?',
                [$newMd, $id, $storedMd]
            );
        }

        if ($upd->rowCount() > 0) {
            return $newMd;
        }

        $stmt = $this->getConnection()->execute(
            'SELECT md_id FROM sys_users WHERE id = ? AND deleted = 0 LIMIT 1',
            [$id]
        );
        $rowAfter = $stmt->fetch('assoc');
        if ($rowAfter === false) {
            return 0;
        }

        $afterMd = (int)$rowAfter['md_id'];

        return $this->isEligibleDoctorAdmin($afterMd) ? $afterMd : 0;
    }

    /**
     * Returns configured active FILLERS doctor id, or 0 when unavailable/invalid.
     */
    public function getFillersDoctorId(): int
    {
        $configuredId = (int)env(self::FILLERS_DOCTOR_ENV_KEY, '0');
        if ($configuredId <= 0) {
            return 0;
        }

        return $this->isEligibleDoctorAdmin($configuredId) ? $configuredId : 0;
    }

    /**
     * Assign doctor by business context.
     * - FILLERS contexts use configured dedicated MD when available.
     * - Non-FILLERS keep current assignment behavior.
     *
     * @param int $injectorId sys_users.id
     * @param array<string,mixed> $context
     * @return int sys_users_admin.id
     */
    public function getAssignedDoctorForContext(int $injectorId, array $context = []): int
    {
        $injectorId = (int)$injectorId;
        if ($injectorId <= 0) {
            return 0;
        }

        $isFillers = !empty($context['isFillers']);
        if ($isFillers) {
            $fillersMdId = $this->getFillersDoctorId();
            if ($fillersMdId > 0) {
                $stmt = $this->getConnection()->execute(
                    'SELECT md_id FROM sys_users WHERE id = ? AND deleted = 0 LIMIT 1',
                    [$injectorId]
                );
                $row = $stmt->fetch('assoc');
                if ($row === false) {
                    return 0;
                }

                $storedMd = (int)$row['md_id'];
                if ($storedMd <= 0 || !$this->isEligibleDoctorAdmin($storedMd)) {
                    if ($storedMd <= 0) {
                        $this->getConnection()->execute(
                            'UPDATE sys_users SET md_id = ? WHERE id = ? AND deleted = 0 AND IFNULL(md_id, 0) = 0',
                            [$fillersMdId, $injectorId]
                        );
                    } else {
                        $this->getConnection()->execute(
                            'UPDATE sys_users SET md_id = ? WHERE id = ? AND deleted = 0 AND md_id = ?',
                            [$fillersMdId, $injectorId, $storedMd]
                        );
                    }
                }

                return $fillersMdId;
            }
        }

        return $this->getAssignedDoctorInjector($injectorId);
    }
}
