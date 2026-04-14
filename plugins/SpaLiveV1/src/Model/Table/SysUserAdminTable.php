<?php
namespace SpaLiveV1\Model\Table;

use Cake\ORM\Table;

class SysUserAdminTable extends Table
{
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
     * Picks the active DOCTOR with the fewest injectors (type injector / gfe+ci) already assigned.
     * Ties are broken at random so new signups spread across MDs with the same count (~one-each).
     *
     * @return int Admin doctor id, or 0 if none
     */
    private function pickLeastLoadedDoctorIdForInjectors(): int
    {
        $doctorIds = $this->eligibleDoctorAdminIds();
        if ($doctorIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));
        $sql = "SELECT u.md_id AS md_id, COUNT(*) AS c FROM sys_users u
            WHERE u.deleted = 0
              AND u.md_id IN ($placeholders)
              AND u.type IN ('injector', 'gfe+ci')
            GROUP BY u.md_id";
        $stmt = $this->getConnection()->execute($sql, $doctorIds);
        $countsByMd = [];
        while ($row = $stmt->fetch('assoc')) {
            $countsByMd[(int)$row['md_id']] = (int)$row['c'];
        }

        $minCount = PHP_INT_MAX;
        foreach ($doctorIds as $docId) {
            $c = $countsByMd[$docId] ?? 0;
            if ($c < $minCount) {
                $minCount = $c;
            }
        }

        $candidates = [];
        foreach ($doctorIds as $docId) {
            $c = $countsByMd[$docId] ?? 0;
            if ($c === $minCount) {
                $candidates[] = $docId;
            }
        }

        return (int)$candidates[array_rand($candidates)];
    }

    /**
     * Same rule as getAssignedDoctorInjector for new md_id: least-loaded among active DOCTORs.
     */
    public function pickBalancedDoctorIdForNewAssignment(): int
    {
        return $this->pickLeastLoadedDoctorIdForInjectors();
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
     * Returns sys_users.md_id for this user. If zero, assigns least-loaded active DOCTOR (injector load) and persists.
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

        $mdId = (int)$row['md_id'];
        if ($mdId > 0) {
            return $mdId;
        }

        $newMd = $this->pickLeastLoadedDoctorIdForInjectors();
        if ($newMd <= 0) {
            return 0;
        }

        $this->getConnection()->update('sys_users', ['md_id' => $newMd], ['id' => $id, 'deleted' => 0]);

        return $newMd;
    }
}
