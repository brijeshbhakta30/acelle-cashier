<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

class DirectController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Get return url.
     *
     * @return string
     **/
    public function getReturnUrl(Request $request) {
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }

        return $return_url;
    }
    
    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Cashier::getPaymentGateway('direct');
    }
    
    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function checkout(Request $request, $subscription_id)
    {
        $service = $this->getPaymentService();
        $subscription = Subscription::findByUid($subscription_id);
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // if subscription is active
        if ($subscription->isActive() || $subscription->isEnded()) {
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::direct.checkout', [
            'service' => $service,
            'subscription' => $subscription,
            'transaction' => $service->getInitTransaction($subscription),
        ]);
    }
    
    /**
     * Claim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function claim(Request $request, $subscription_id)
    {
        // subscription and service
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        $service->claim($service->getInitTransaction($subscription));
        
        return redirect()->action('\Acelle\Cashier\Controllers\DirectController@checkout', [
            'subscription_id' => $subscription->uid,
        ]);
    }
    
    /**
     * Unclaim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function unclaim(Request $request, $subscription_id)
    {
        // subscription and service
        $subscription = Subscription::findByUid($subscription_id);
        $gatewayService = $this->getPaymentService();
        
        $gatewayService->unclaim($subscription);
        
        return redirect()->action('\Acelle\Cashier\Controllers\DirectController@checkout', [
            'subscription_id' => $subscription->uid,
        ]);
    }
    
    /**
     * Subscription pending page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function pending(Request $request, $subscription_id)
    {
        $service = $this->getPaymentService();
        $subscription = Subscription::findByUid($subscription_id);
        $transaction = $service->getLastTransaction($subscription);
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        if (!$transaction->isPending()) {            
            return redirect()->away($this->getReturnUrl($request));
        }
        
        if ($transaction->type == SubscriptionTransaction::TYPE_RENEW) {
            return view('cashier::direct.pending_renew', [
                'service' => $service,
                'subscription' => $subscription,
                'transaction' => $transaction,
                'return_url' => $this->getReturnUrl($request),
            ]);
        } elseif ($transaction->type == SubscriptionTransaction::TYPE_PLAN_CHANGE) {
            $plan = \Acelle\Model\Plan::findByUid($transaction->getMetadata()['plan_id']);

            return view('cashier::direct.pending_plan_change', [
                'service' => $service,
                'subscription' => $subscription,
                'transaction' => $transaction,
                'return_url' => $this->getReturnUrl($request),
                'plan' => $plan,
            ]);
        } else {
            return view('cashier::direct.pending', [
                'service' => $service,
                'subscription' => $subscription,
                'transaction' => $transaction,
                'return_url' => $this->getReturnUrl($request),
            ]);
        }
    }
    
    /**
     * Claim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function pendingClaim(Request $request, $subscription_id)
    {
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        $transaction = $service->getLastTransaction($subscription);
        
        $service->claim($transaction);

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_CLAIMED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $transaction->amount,
        ]);
        
        return redirect()->action('\Acelle\Cashier\Controllers\DirectController@pending', [
            'subscription_id' => $subscription->uid,
        ]);
    }
    
    /**
     * Unclaim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function pendingUnclaim(Request $request, $subscription_id)
    {
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        $transaction = $service->getLastTransaction($subscription);
        
        $service->unclaim($transaction);

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_UNCLAIMED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
        
        return redirect()->action('\Acelle\Cashier\Controllers\DirectController@pending', [
            'subscription_id' => $subscription->uid,
        ]);
    }
    
    /**
     * Renew subscription.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function renew(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // check if status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($this->getReturnUrl($request));
        }
        
        if ($request->isMethod('post')) {
            // subscribe to plan
            $subscription->addTransaction(SubscriptionTransaction::TYPE_RENEW, [
                'ends_at' => $subscription->nextPeriod(),
                'current_period_ends_at' => $subscription->nextPeriod(),
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.renew_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice(),
                'description' => trans('cashier::messages.direct.payment_is_not_claimed'),
            ]);

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEW, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // if is free
            if ($subscription->plan->getBillableAmount() == 0) {
                $service->approvePending($subscription);
            }

            // Redirect to my subscription page
            return redirect()->action('\Acelle\Cashier\Controllers\DirectController@pending', [
                'subscription_id' => $subscription->uid,
            ]);
        }
        
        return view('cashier::direct.renew', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $request->return_url,
        ]);
    }
    
    /**
     * Renew subscription.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function changePlan(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // @todo dependency injection 
        $plan = \Acelle\Model\Plan::findByUid($request->plan_id);        
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // check if status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($request->return_url);
        }

        // calc plan before change
        try {
            $result = Cashier::calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', 'Can not change plan: ' . $e->getMessage());
            return redirect()->away($request->return_url);
        }
        
        if ($request->isMethod('post')) {
            // subscribe to plan
            $plan->price = $result['amount'];
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
                'ends_at' => $result['endsAt'],
                'current_period_ends_at' => $result['endsAt'],
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.change_plan', [
                    'plan' => $plan->getBillableName(),
                ]),
                'amount' => $plan->getBillableFormattedPrice(),
                'description' => trans('cashier::messages.direct.payment_is_not_claimed'),
            ]);

            // save new plan uid
            $data = $transaction->getMetadata();
            $data['plan_id'] = $plan->getBillableId();
            $transaction->updateMetadata($data);
            
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);

            // if is free
            if ($result['amount'] == 0) {
                $service->approvePending($subscription);
            }

            // Redirect to my subscription page
            return redirect()->action('\Acelle\Cashier\Controllers\DirectController@pending', [
                'subscription_id' => $subscription->uid,
            ]);
        }
        
        return view('cashier::direct.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $request->return_url,
            'nextPeriodDay' => $result['endsAt'],
            'amount' => $result['amount'],
        ]);
    }
    
    /**
     * Cancel new subscription.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function cancelNow(Request $request, $subscription_id)
    {
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();

        if ($subscription->isPending()) {
            $subscription->setEnded();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);
        }

        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }

        // Redirect to my subscription page
        return redirect()->away($return_url);
    }
}