<?php

namespace App\Policies;

use App\Models\Order;
use Illuminate\Foundation\Auth\User;

class OrderPolicy
{
    /**
     * Determine if user can view this order
     */
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }

    /**
     * Determine if user can update this order (limited capabilities)
     */
    public function update(User $user, Order $order): bool
    {
        // Only allow updates if order is in certain states
        return $user->id === $order->user_id && !$order->isFinal();
    }

    /**
     * Determine if user can cancel this order
     */
    public function cancel(User $user, Order $order): bool
    {
        // Only allow cancellation if order hasn't been finalized
        return $user->id === $order->user_id && $order->status->value !== 'confirmed';
    }

    /**
     * Determine if user can delete this order
     */
    public function delete(User $user, Order $order): bool
    {
        // Only admins can delete orders
        return false; // Implement based on your user roles
    }
}
