<?php

/**
 * Planning
 *
 * - Defines common contract methods for all repositories.
 * - Promotes loose coupling and consistency across implementations.
 * - Provides signatures for:
 *   - `create`, `update`, `delete`, `all`, `findById`, `findByName`.
 * - Acts as a blueprint to enforce Repository design pattern.
 * - Future Planning:
 *   - Consider separating optional methods like `findByName` into domain-specific interfaces.
 *   - Expand to include `paginate`, `findWhere`, etc.
 */

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Responsibilities for an interface is:
 * 1. Define method signatures
 * 2. Ensure Consistency
 * 3. Promote loose coupling by decoupling.
 * 4. Enable Polymorphism.
 * 5. Encourage dependency injection.
 * 6. Facilitate testability by mocking objects and stubs.
 * 7. Allow for multiple inplementations of the same functionality.
 */


interface BaseInterface
{
    public function create(array $data);
    public function all();
    public function update(int $id, array $data);
    public function delete(int $id);
    public function findById(int $id): ?Model;
}
