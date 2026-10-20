<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameBookingRequest;
use App\Services\Games\GameBookingService;
use Illuminate\Http\JsonResponse;

class GameBookingController extends Controller
{
    public function __construct(private GameBookingService $bookings) {}

    public function index(GameBookingRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->bookings->listing($request->user())])->header('Cache-Control', 'no-store');
    }

    public function payments(GameBookingRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->bookings->payments($request->user())])->header('Cache-Control', 'no-store');
    }

    public function show(GameBookingRequest $request, string $booking_id): JsonResponse
    {
        return response()->json(['data' => $this->bookings->show($request->user(), $booking_id)])->header('Cache-Control', 'no-store');
    }

    public function create(GameBookingRequest $request, string $game_id): JsonResponse
    {
        return response()->json(['data' => $this->bookings->create($request->user(), $game_id, $request->validated(), $request->header('Idempotency-Key'))], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function checkout(GameBookingRequest $request, string $order_id): JsonResponse
    {
        return response()->json(['data' => $this->bookings->startCheckout($request->user(), $order_id)])->header('Cache-Control', 'no-store');
    }

    public function confirmTestPayment(GameBookingRequest $request, string $order_id): JsonResponse
    {
        return response()->json(['data' => $this->bookings->confirmTestPayment($request->user(), $order_id)])->header('Cache-Control', 'no-store');
    }
}
