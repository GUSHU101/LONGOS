@php
  $currentStep = min(3, max(1, (int) $steps));
  $checkoutSteps = [
    1 => __('shop/steps.cart'),
    2 => __('shop/steps.checkout'),
    3 => __('shop/steps.payment'),
  ];
@endphp
<nav class="checkout-steps" aria-label="{{ __('shop/steps.index') }}">
  <ol class="steps-wrap">
    @foreach ($checkoutSteps as $stepNumber => $stepTitle)
      <li class="{{ $stepNumber <= $currentStep ? 'active' : '' }}" @if($stepNumber === $currentStep) aria-current="step" @endif>
        <div class="number-wrap"><span class="number">{{ $stepNumber }}</span></div>
        <span class="title">{{ $stepTitle }}</span>
      </li>
    @endforeach
  </ol>
</nav>
