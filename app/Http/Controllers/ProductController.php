<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Exception;
use Illuminate\Http\Request;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Stripe;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductController extends Controller
{
    public function index (Request $request)
    {
        $products = Product::all();
        return view('product.index', ['products' => $products]);
    }

    public function checkout()
    {
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        $products = Product::all();
        $lineItem = [];
        $totalPrice = 0;
        foreach($products as $product){
            $totalPrice += $product->price;
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $product->name,
                        'images' => [$product->image]
                    ],
                    'unit_amount' => $product->price * 100,
                ],
                'quantity' => 1,
            ];
        }
        $session = Session::create([
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => route('checkout.success', [], true)."?session_id={CHECKOUT_SESSION_ID}",
            'cancel_url' => route('checkout.cancel', [], true),
          ]);

        $order = new Order();
        $order->status = 'unpaid';
        $order->total_price = $totalPrice;
        $order->session_id = $session->id;
        $order->save();

        return redirect($session->url);
    }

    public function success(Request $request)
    {
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        $sessionId = $request->get('session_id');

        try {
            $session = Session::retrieve($sessionId);
            if(!$session)
            {
                throw new NotFoundHttpException;
            }

            $customer = $session->customer_details;
            $order = Order::where('session_id', $session->id)->first();
            if(!$order)
            {
                throw new NotFoundHttpException;
            }

            if($order->status === 'unpaid'){
                $order->status = 'paid';
                $order->save();
            }

            return view('product.checkout-success', ['customer' => $customer]);

        } catch(Exception $e){
            throw new NotFoundHttpException();
        }
    }

    public function webhook()
    {
        // This is your Stripe CLI webhook secret for testing your endpoint locally.
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;

        try {
        $event = \Stripe\Webhook::constructEvent(
            $payload, $sig_header, $endpoint_secret
        );
        } catch(\UnexpectedValueException $e) {
            return response('', 400);
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
        // Invalid signature
            return response('', 400);
        }

        // Handle the event
        switch ($event->type) {
          case 'checkout.session.completed':
            $session = $event->data->object;

            $order = Order::where('session_id', $session->id)->first();

            if($order && $order->status === 'unpaid'){
                $order->status = 'paid';
                $order->save();

                //Send email to customer here
            }

        // ... handle other event types
        default:
            echo 'Received unknown event type ' . $event->type;
        }

        return response('');
    }

    public function cancel()
    {

    }
}
