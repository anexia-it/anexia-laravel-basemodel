<?php

namespace Anexia\BaseModel\Database;

use Illuminate\Database\Connection as BaseConnection;

class Connection extends BaseConnection
{
    /** @var int count active transactions */
    protected $transCount = 0;

    public function beginTransaction()
    {
        if (!$this->transCount++) {
            return parent::beginTransaction();
        }
        // create sub transaction
        $this->getPdo()->exec('SAVEPOINT trans' . $this->transCount);
        return $this->transCount >= 0;
    }

    public function commit()
    {
        if (!--$this->transCount) {
            return parent::commit();
        }
        return $this->transCount >= 0;
    }

    public function rollback()
    {
        if (--$this->transCount) {
            $this->getPdo()->exec('ROLLBACK TO trans' . ($this->transCount + 1));
            return true;
        }
        return parent::rollback();
    }
}