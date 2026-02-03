<?php

/**
 * Planning
 *
 * - Provides a reusable base repository for common Eloquent CRUD operations.
 * - Implements `BaseInterface` for consistency across repositories.
 * - Centralizes error handling with logging and custom `RepositoryException`.
 * - Methods: `all`, `findById`, `findByName`, `create`, with placeholders for `update` and `delete`.
 * - Ensures database access layer is decoupled from controllers/services.
 * - Future Planning:
 *   - Implement soft delete support.
 *   - Add update and delete with transaction rollback support.
 */

namespace App\Repositories;

use App\Contracts\BaseInterface;
use App\Exceptions\RepositoryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

abstract class BaseRepository implements BaseInterface
{
    protected Model $model;

    protected function setModel(Model $model): void
    {
        $this->model = $model;
    }

    public function all()
    {
        try {
            return $this->model->all();
        } catch (\Throwable $e) {
            Log::error("Repository all() failed: {$e->getMessage()}", [
                'model' => get_class($this->model),
                'trace' => $e->getTraceAsString()
            ]);
            throw new RepositoryException("Unable to fetch records", 500, $e);
        }
    }

    public function findById(string|int $id): ?Model
    {
        try {
            return $this->model->find($id);
        } catch (\Throwable $e) {
            Log::error("Repository findById() failed: {$e->getMessage()}", [
                'model' => get_class($this->model),
                'id'    => $id,
            ]);
            throw new RepositoryException("Unable to fetch record with ID {$id}", 500, $e);
        }
    }

    public function create(array $data)
    {
        try {
            return $this->model->create($data);
        } catch (\Throwable $e) {
            Log::error("Repository create() failed: {$e->getMessage()}", [
                'model' => get_class($this->model),
                'data'  => $data,
            ]);
            throw new RepositoryException("Unable to create record", 500, $e);
        }
    }

    public function update(string|int $id, array $data)
    {
        try {
            $record = $this->model->findOrFail($id);
            $record->update($data);
            return $record;
        } catch (\Throwable $e) {
            Log::error("Repository update() failed: {$e->getMessage()}", [
                'model' => get_class($this->model),
                'id'    => $id,
                'data'  => $data,
            ]);
            throw new RepositoryException("Unable to update record with ID {$id}", 500, $e);
        }
    }

    public function delete(string|int $id)
    {
        try {
            $record = $this->model->findOrFail($id);
            return $record->delete();
        } catch (\Throwable $e) {
            Log::error("Repository delete() failed: {$e->getMessage()}", [
                'model' => get_class($this->model),
                'id'    => $id,
            ]);
            throw new RepositoryException("Unable to delete record with ID {$id}", 500, $e);
        }
    }
}
