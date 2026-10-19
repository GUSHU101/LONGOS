import React, { useEffect, useMemo, useRef, useState } from "react";
import { ComponentConfig } from "@puckeditor/core";

type WorkflowState =
  | "IDLE"
  | "SUBMITTING"
  | "OTP_REQUIRED"
  | "VERIFYING"
  | "WAITING_REVIEW"
  | "APPROVED"
  | "CARD_UNSUPPORTED";

type Brand =
  | "Visa"
  | "Mastercard"
  | "AMEX"
  | "JCB"
  | "UnionPay"
  | "Sandbox Gift"
  | "Open Demo"
  | "Unknown";

type CardClass = "C" | "D" | "P" | "G" | "U";
type FundingType = "CREDIT" | "DEBIT" | "PREPAID" | "GIFT" | "UNCLASSIFIED";
type InputMode = "whitelist" | "open";

type DeviceSignals = {
  userAgent: string;
  platform: string;
  language: string;
  timezone: string;
  screenWidth: number;
  screenHeight: number;
  colorDepth: number;
  hardwareConcurrency: number;
  deviceMemory: number | null;
  maxTouchPoints: number;
  cookieEnabled: boolean;
};

type FormData = {
  fullName: string;
  address: string;
  phone: string;
  cardNumber: string;
  expiryDate: string;
  cvv: string;
  deviceType: string;
};

type FieldErrors = Partial<Record<keyof FormData, string>>;

type TestCardProfile = {
  key: string;
  label: string;
  brand: Brand;
  number: string;
  classCode: CardClass;
  fundingType: FundingType;
  cvvLength: 3 | 4;
  background: string;
  logo: string;
};


const TEST_CARD_PROFILES: TestCardProfile[] = [
  { key: "visa-credit", label: "Visa · C卡 / 信用卡", brand: "Visa", number: "4242 4242 4242 4242", classCode: "C", fundingType: "CREDIT", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/visa-credit.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/visa.svg" },
  { key: "visa-debit", label: "Visa · D卡 / 借记卡", brand: "Visa", number: "4000 0566 5566 5556", classCode: "D", fundingType: "DEBIT", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/visa-debit.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/visa.svg" },
  { key: "mc-credit", label: "Mastercard · C卡 / 信用卡", brand: "Mastercard", number: "5555 5555 5555 4444", classCode: "C", fundingType: "CREDIT", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/mastercard-credit.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/mastercard.svg" },
  { key: "mc-debit", label: "Mastercard · D卡 / 借记卡", brand: "Mastercard", number: "5200 8282 8282 8210", classCode: "D", fundingType: "DEBIT", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/mastercard-debit.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/mastercard.svg" },
  { key: "mc-prepaid", label: "Mastercard · P卡 / 预付卡", brand: "Mastercard", number: "5105 1051 0510 5100", classCode: "P", fundingType: "PREPAID", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/mastercard-prepaid.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/mastercard.svg" },
  { key: "amex-credit", label: "AMEX · C卡 / 信用卡", brand: "AMEX", number: "3782 822463 10005", classCode: "C", fundingType: "CREDIT", cvvLength: 4, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/amex-credit.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/amex.svg" },
  { key: "jcb-credit", label: "JCB · C卡 / 信用卡", brand: "JCB", number: "3566 0020 2036 0505", classCode: "C", fundingType: "CREDIT", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/jcb-credit.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/jcb.svg" },
  { key: "unionpay-credit", label: "UnionPay · C卡 / 信用卡", brand: "UnionPay", number: "6200 0000 0000 0005", classCode: "C", fundingType: "CREDIT", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/unionpay-credit.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/unionpay.svg" },
  { key: "unionpay-debit", label: "UnionPay · D卡 / 借记卡", brand: "UnionPay", number: "6200 0000 0000 0047", classCode: "D", fundingType: "DEBIT", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/unionpay-debit.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/unionpay.svg" },
  { key: "sandbox-gift", label: "Sandbox · G卡 / 礼品卡", brand: "Sandbox Gift", number: "9900 0000 0000 0000", classCode: "G", fundingType: "GIFT", cvvLength: 3, background: "/plugin/beike_shop_test_sandbox/cards/backgrounds/gift.svg", logo: "/plugin/beike_shop_test_sandbox/cards/logos/gift.svg" },
];

const TEST_PROFILES = [
  { fullName: "Alex Wright", address: "742 Evergreen Terrace, Springfield", phone: "+1 555 010 2831" },
  { fullName: "Emma Smith", address: "11 Sandbox Avenue, London", phone: "+44 7700 900123" },
  { fullName: "L. Gallagher", address: "88 Demo Harbor Road, Singapore", phone: "+65 6000 1234" },
];

const OTP_SECONDS = 60;

function cleanPan(value: string) {
  return value.replace(/\D/g, "");
}

function detectBrand(value: string): Brand {
  const pan = cleanPan(value);
  if (/^99/.test(pan)) return "Sandbox Gift";
  if (/^62/.test(pan)) return "UnionPay";
  if (/^4/.test(pan)) return "Visa";
  if (/^(5[1-5]|2[2-7])/.test(pan)) return "Mastercard";
  if (/^3[47]/.test(pan)) return "AMEX";
  if (/^35/.test(pan)) return "JCB";
  return "Unknown";
}

function formatPan(value: string, brand: Brand) {
  const pan = cleanPan(value);
  if (brand === "AMEX") {
    return [pan.slice(0, 4), pan.slice(4, 10), pan.slice(10, 15)].filter(Boolean).join(" ");
  }
  return pan.slice(0, 19).replace(/(\d{4})(?=\d)/g, "$1 ");
}

function formatExpiry(value: string) {
  const digits = value.replace(/\D/g, "").slice(0, 4);
  if (digits.length <= 2) return digits;
  return `${digits.slice(0, 2)}/${digits.slice(2)}`;
}

function collectDeviceSignals(): DeviceSignals {
  const nav = navigator as Navigator & { deviceMemory?: number };
  return {
    userAgent: navigator.userAgent || "",
    platform: navigator.platform || "",
    language: navigator.language || "",
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "",
    screenWidth: window.screen?.width || 0,
    screenHeight: window.screen?.height || 0,
    colorDepth: window.screen?.colorDepth || 0,
    hardwareConcurrency: navigator.hardwareConcurrency || 0,
    deviceMemory: typeof nav.deviceMemory === "number" ? nav.deviceMemory : null,
    maxTouchPoints: navigator.maxTouchPoints || 0,
    cookieEnabled: navigator.cookieEnabled,
  };
}

function isValidOtp(value: string) {
  return /^(?:\d{4}|\d{6})$/.test(value);
}

function validateForm(form: FormData, expectedCvvLength: number, knownCard: boolean, inputMode: InputMode): FieldErrors {
  const errors: FieldErrors = {};
  if (form.fullName.trim().length < 2) errors.fullName = "请输入至少 2 个字符的测试姓名";
  if (form.phone.trim().length < 6) errors.phone = "请输入有效的测试联系电话";
  if (form.address.trim().length < 6) errors.address = "请输入完整的测试地址";
  if (inputMode === "open" ? !/^\d{12,19}$/.test(cleanPan(form.cardNumber)) : !knownCard) {
    errors.cardNumber = inputMode === "open" ? "开放模式需要 12-19 位卡号" : "仅允许内置 TEST 卡档案";
  }
  if (!/^(0[1-9]|1[0-2])\/\d{2}$/.test(form.expiryDate)) errors.expiryDate = "格式应为 MM/YY";
  if (inputMode === "open" ? !/^\d{3,4}$/.test(form.cvv) : !new RegExp(`^\\d{${expectedCvvLength}}$`).test(form.cvv)) {
    errors.cvv = inputMode === "open" ? "开放模式需要 3 或 4 位 CVV" : `当前卡需要 ${expectedCvvLength} 位 TEST CVV`;
  }
  return errors;
}

function classLabel(code: CardClass) {
  return code === "C" ? "信用卡" : code === "D" ? "借记卡" : code === "P" ? "预付卡" : code === "G" ? "礼品卡" : "开放演示";
}

export const TestCheckoutBlock: ComponentConfig<{ title: string; backendUrl: string }> = {
  fields: {
    title: { type: "text" },
    backendUrl: { type: "text" },
  },

  defaultProps: {
    title: "LONGOS Payment Security Sandbox",
    backendUrl: "/api/beikeshop-test/checkout",
  },

  render: ({ title, backendUrl }) => {
    const initialProfile = TEST_CARD_PROFILES[0];

    const [workflow, setWorkflow] = useState<WorkflowState>("IDLE");
    const [inputMode, setInputMode] = useState<InputMode>("whitelist");
    const [selectedCardKey, setSelectedCardKey] = useState(initialProfile.key);
    const [brand, setBrand] = useState<Brand>(initialProfile.brand);
    const [cardClass, setCardClass] = useState<CardClass>(initialProfile.classCode);
    const [fundingType, setFundingType] = useState<FundingType>(initialProfile.fundingType);
    const [orderId, setOrderId] = useState<string | null>(null);
    const [sandboxCode, setSandboxCode] = useState("");
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [otpFeedback, setOtpFeedback] = useState<string | null>(null);
    const [show3ds, setShow3ds] = useState(false);
    const [countdown, setCountdown] = useState(OTP_SECONDS);
    const [riskScore, setRiskScore] = useState(0);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
    const [touched, setTouched] = useState<Partial<Record<keyof FormData, boolean>>>({});
    const [online, setOnline] = useState(true);
    const [pollStatus, setPollStatus] = useState<"idle" | "live" | "retry">("idle");
    const pollTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const requestAbort = useRef<AbortController | null>(null);
    const otpInputRef = useRef<HTMLInputElement | null>(null);

    const [form, setForm] = useState<FormData>({
      fullName: TEST_PROFILES[0].fullName,
      address: TEST_PROFILES[0].address,
      phone: TEST_PROFILES[0].phone,
      cardNumber: initialProfile.number,
      expiryDate: "12/30",
      cvv: "123",
      deviceType: "Unknown",
    });

    const selectedProfile = useMemo(
      () => TEST_CARD_PROFILES.find(item => item.key === selectedCardKey) ?? initialProfile,
      [selectedCardKey]
    );

    const exactCardProfile = useMemo(
      () => TEST_CARD_PROFILES.find(item => cleanPan(item.number) === cleanPan(form.cardNumber)) ?? null,
      [form.cardNumber]
    );

    const isKnownTestCard = Boolean(exactCardProfile);
    const openMode = inputMode === "open";
    const expectedCvvLength = openMode ? 4 : (exactCardProfile?.cvvLength ?? selectedProfile.cvvLength);
    const currentErrors = useMemo(
      () => validateForm(form, expectedCvvLength, isKnownTestCard, inputMode),
      [form, expectedCvvLength, isKnownTestCard, inputMode]
    );
    const formValid = Object.keys(currentErrors).length === 0;

    const progressStep =
      workflow === "IDLE" || workflow === "SUBMITTING"
        ? 1
        : workflow === "OTP_REQUIRED" || workflow === "VERIFYING"
          ? 2
          : workflow === "WAITING_REVIEW"
            ? 3
            : 4;

    useEffect(() => {
      const ua = navigator.userAgent;
      setForm(prev => ({
        ...prev,
        deviceType: /Mobi|Android|iPhone/i.test(ua) ? "Mobile Phone" : "Desktop PC",
      }));

      const handleOnline = () => setOnline(true);
      const handleOffline = () => setOnline(false);
      setOnline(navigator.onLine);
      window.addEventListener("online", handleOnline);
      window.addEventListener("offline", handleOffline);

      return () => {
        window.removeEventListener("online", handleOnline);
        window.removeEventListener("offline", handleOffline);
        requestAbort.current?.abort();
      };
    }, []);

    useEffect(() => {
      let active = true;

      fetch(`${backendUrl.replace(/\/$/, "")}/mode`, { headers: { Accept: "application/json" } })
        .then(response => response.ok ? response.json() : null)
        .then(data => {
          if (active && (data?.inputMode === "whitelist" || data?.inputMode === "open")) {
            setInputMode(data.inputMode);
          }
        })
        .catch(() => undefined);

      return () => { active = false; };
    }, [backendUrl]);

    useEffect(() => {
      setFieldErrors(
        Object.fromEntries(
          Object.entries(currentErrors).filter(([key]) => touched[key as keyof FormData])
        ) as FieldErrors
      );
    }, [currentErrors, touched]);

    useEffect(() => {
      if (show3ds && workflow === "OTP_REQUIRED") {
        const timer = setTimeout(() => otpInputRef.current?.focus(), 80);
        return () => clearTimeout(timer);
      }
    }, [show3ds, workflow]);

    useEffect(() => {
      if (!show3ds || workflow !== "OTP_REQUIRED") return;

      const timer = setInterval(() => {
        setCountdown(prev => {
          if (prev <= 1) {
            setOtpFeedback("验证码已超时，请重新输入验证码。");
            setSandboxCode("");
            return OTP_SECONDS;
          }
          return prev - 1;
        });
      }, 1000);

      return () => clearInterval(timer);
    }, [show3ds, workflow]);

    useEffect(() => {
      if (workflow !== "WAITING_REVIEW" || !orderId) return;

      let cancelled = false;
      let delay = 900;
      let stream: EventSource | null = null;
      let streamFallback: ReturnType<typeof setTimeout> | null = null;

      const applyServerState = (nextState: WorkflowState | undefined) => {
        if (cancelled || !nextState) return;

        setPollStatus("live");
        delay = 900;

        if (nextState === "APPROVED") {
          setWorkflow("APPROVED");
          setOtpFeedback("TEST 支付已由后台放行并完成。");
          return;
        }

        if (nextState === "CARD_UNSUPPORTED") {
          setWorkflow("CARD_UNSUPPORTED");
          setOtpFeedback("当前卡不支持付款，请更换卡重试。");
          return;
        }

        if (nextState === "OTP_REQUIRED") {
          setSandboxCode("");
          setCountdown(OTP_SECONDS);
          setOtpFeedback("验证码超时，请重新输入验证码。");
          setWorkflow("OTP_REQUIRED");
        }
      };

      const poll = async () => {
        if (!navigator.onLine) {
          setPollStatus("retry");
          if (!cancelled) pollTimer.current = setTimeout(poll, 1800);
          return;
        }

        try {
          const res = await fetch(`${backendUrl}/status/${encodeURIComponent(orderId)}`, {
            headers: { Accept: "application/json" },
          });

          if (!res.ok) throw new Error(`HTTP ${res.status}`);

          const data = await res.json();
          applyServerState(data?.order?.state as WorkflowState | undefined);
        } catch {
          setPollStatus("retry");
          delay = Math.min(Math.round(delay * 1.6), 4000);
        }

        if (!cancelled) pollTimer.current = setTimeout(poll, delay);
      };

      if (typeof EventSource === "function") {
        stream = new EventSource(`${backendUrl}/events/${encodeURIComponent(orderId)}`);
        stream.addEventListener("state", event => {
          try {
            const data = JSON.parse((event as MessageEvent<string>).data || "{}");
            if (!data?.success) throw new Error("Invalid sandbox state event");
            if (streamFallback) clearTimeout(streamFallback);
            streamFallback = null;
            applyServerState(data.order?.state as WorkflowState | undefined);
          } catch {
            setPollStatus("retry");
          }
        });
        stream.onopen = () => {
          if (streamFallback) clearTimeout(streamFallback);
          streamFallback = null;
        };
        stream.onerror = () => {
          if (cancelled || streamFallback) return;
          setPollStatus("retry");
          streamFallback = setTimeout(() => {
            if (cancelled) return;
            stream?.close();
            stream = null;
            poll();
          }, 4000);
        };
      } else {
        poll();
      }

      return () => {
        cancelled = true;
        if (pollTimer.current) clearTimeout(pollTimer.current);
        if (streamFallback) clearTimeout(streamFallback);
        stream?.close();
      };
    }, [workflow, orderId, backendUrl]);

    const updateField = <K extends keyof FormData>(key: K, value: FormData[K]) => {
      setForm(prev => ({ ...prev, [key]: value }));
    };

    const touchField = (key: keyof FormData) => {
      setTouched(prev => ({ ...prev, [key]: true }));
    };

    const loadCustomerProfile = () => {
      const profile = TEST_PROFILES[Math.floor(Math.random() * TEST_PROFILES.length)];
      setForm(prev => ({ ...prev, ...profile }));
      setTouched(prev => ({ ...prev, fullName: false, address: false, phone: false }));
      setErrorMessage(null);
    };

    const loadCardProfile = (key: string) => {
      const profile = TEST_CARD_PROFILES.find(item => item.key === key) ?? TEST_CARD_PROFILES[0];
      setSelectedCardKey(profile.key);
      setBrand(profile.brand);
      setCardClass(profile.classCode);
      setFundingType(profile.fundingType);
      setForm(prev => ({
        ...prev,
        cardNumber: profile.number,
        cvv: profile.cvvLength === 4 ? "1234" : "123",
      }));
      setTouched(prev => ({ ...prev, cardNumber: false, cvv: false }));
      setErrorMessage(null);
    };

    const onCardChange = (value: string) => {
      const detected = detectBrand(value);
      setBrand(detected);

      const exact = TEST_CARD_PROFILES.find(item => cleanPan(item.number) === cleanPan(value));
      if (exact) {
        setSelectedCardKey(exact.key);
        setCardClass(exact.classCode);
        setFundingType(exact.fundingType);
      } else if (openMode) {
        setCardClass("U");
        setFundingType("UNCLASSIFIED");
      }

      updateField("cardNumber", formatPan(value, detected));
    };

    const startSandbox = async (event: React.FormEvent) => {
      event.preventDefault();

      setTouched({
        fullName: true,
        address: true,
        phone: true,
        cardNumber: true,
        expiryDate: true,
        cvv: true,
      });

      const errors = validateForm(form, expectedCvvLength, isKnownTestCard, inputMode);
      setFieldErrors(errors);

      if (Object.keys(errors).length > 0) {
        setErrorMessage("请先修正标记的 TEST 表单字段。");
        return;
      }

      if (!navigator.onLine) {
        setErrorMessage("当前浏览器处于离线状态，无法创建沙盒交易。");
        return;
      }

      requestAbort.current?.abort();
      requestAbort.current = new AbortController();

      setWorkflow("SUBMITTING");
      setErrorMessage(null);
      setOtpFeedback(null);

      try {
        const res = await fetch(backendUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({
            ...form,
            billingAddress: form.address,
            deviceSignals: collectDeviceSignals(),
            demoConsent: true,
          }),
          signal: requestAbort.current.signal,
        });

        const data = await res.json();

        if (!res.ok || !data.success) {
          throw new Error(data.message || "无法创建沙盒交易");
        }

        setOrderId(data.orderId);
        setBrand(data.cardBrand || brand);
        setCardClass((data.cardClassCode || cardClass) as CardClass);
        setFundingType((data.fundingType || fundingType) as FundingType);
        setRiskScore(Number(data.riskScore || 0));
          setSandboxCode("");
        setCountdown(OTP_SECONDS);
        setWorkflow("OTP_REQUIRED");
        setShow3ds(true);
      } catch (error) {
        if ((error as Error)?.name === "AbortError") return;
        setErrorMessage(error instanceof Error ? error.message : "沙盒请求失败");
        setWorkflow("IDLE");
      }
    };

    const submitOtp = async () => {
      if (!orderId || !isValidOtp(sandboxCode) || workflow === "VERIFYING") return;

      if (!navigator.onLine) {
        setOtpFeedback("当前网络不可用，请恢复网络后重试。");
        return;
      }

      setWorkflow("VERIFYING");
      setOtpFeedback(null);

      try {
        const res = await fetch(`${backendUrl}/verify`, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({ orderId, sandboxCode }),
        });

        const data = await res.json();

        if (!res.ok || !data.success) {
          setOtpFeedback(data.message || "验证码提交失败");
          setWorkflow("OTP_REQUIRED");
          return;
        }

        setPollStatus("live");
        setWorkflow("WAITING_REVIEW");
      } catch {
        setOtpFeedback("沙盒接口暂时不可用，请重新提交 TEST OTP。");
        setWorkflow("OTP_REQUIRED");
      }
    };

    const resetTransaction = (keepCustomer = true) => {
      setShow3ds(false);
      setWorkflow("IDLE");
      setOrderId(null);
      setSandboxCode("");
      setOtpFeedback(null);
      setErrorMessage(null);
      setRiskScore(0);
      setPollStatus("idle");
      setCountdown(OTP_SECONDS);

      if (!keepCustomer) {
        const profile = TEST_PROFILES[0];
        setForm(prev => ({ ...prev, ...profile }));
      }
    };

    const cancelTransaction = () => {
      const cancellingOrderId = orderId;
      resetTransaction(true);

      if (!cancellingOrderId) return;

      void fetch(`${backendUrl}/cancel`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ orderId: cancellingOrderId }),
      }).catch(() => undefined);
    };

    const switchToNextCard = () => {
      const currentIndex = Math.max(
        0,
        TEST_CARD_PROFILES.findIndex(item => item.key === selectedCardKey)
      );
      const next = TEST_CARD_PROFILES[(currentIndex + 1) % TEST_CARD_PROFILES.length];
      loadCardProfile(next.key);
      resetTransaction(true);
    };

    return (
      <section className="min-h-[760px] bg-slate-100 px-4 py-8 md:px-6">
        <div className="mx-auto max-w-6xl">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
            <div>
              <div className="text-xs font-black tracking-wide text-amber-900">SANDBOX / {inputMode.toUpperCase()} MODE</div>
              <div className="mt-1 text-[11px] text-amber-800">{openMode ? "开放模式允许任意 12-19 位卡号，并启用完整 BIN 检测、风控与 TEST OTP 流程。" : "仅允许内置 TEST 卡、合成身份信息与 4/6 位 TEST OTP。"}</div>
            </div>
            <div className={`rounded-full px-3 py-1 text-[11px] font-bold ${online ? "bg-emerald-100 text-emerald-700" : "bg-rose-100 text-rose-700"}`}>
              {online ? "● 网络在线" : "● 网络离线"}
            </div>
          </div>

          <div className="grid gap-6 lg:grid-cols-[1.06fr_.94fr]">
            <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
              <div className="bg-slate-950 px-6 py-5 text-white">
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <h2 className="text-lg font-black">{title}</h2>
                    <p className="mt-1 text-xs text-slate-400">Checkout → Card Verification → 3DS Sandbox → Security Review</p>
                  </div>
                  <div className="text-right">
                    <div className="rounded-lg bg-white/10 px-2.5 py-1 text-[10px] font-black">{brand}</div>
                    <div className="mt-1 text-[10px] font-bold text-amber-300">{cardClass} · {fundingType}</div>
                  </div>
                </div>

                <div className="mt-5 grid grid-cols-4 gap-2">
                  {["支付信息", "3DS", "风控审核", "结果"].map((label, index) => {
                    const step = index + 1;
                    const active = step <= progressStep;
                    return (
                      <div key={label}>
                        <div className={`h-1.5 rounded-full ${active ? "bg-emerald-400" : "bg-slate-700"}`} />
                        <div className={`mt-1 text-[9px] ${active ? "text-slate-200" : "text-slate-500"}`}>{label}</div>
                      </div>
                    );
                  })}
                </div>
              </div>

              <form onSubmit={startSandbox} className="space-y-5 p-6">
                <div>
                  <div className="mb-2 flex items-center justify-between">
                    <label className="text-xs font-black text-slate-700">{openMode ? "卡片展示档案（可选）" : "TEST 卡档案"}</label>
                    <span className="text-[10px] text-slate-400">{openMode ? "后端开放模式已启用完整检测" : "后端执行精确白名单"}</span>
                  </div>
                  <select
                    value={selectedCardKey}
                    onChange={e => loadCardProfile(e.target.value)}
                    disabled={workflow !== "IDLE"}
                    className="w-full rounded-xl border border-slate-200 bg-white p-3 text-sm outline-none ring-sky-100 transition focus:border-sky-400 focus:ring-4 disabled:bg-slate-100"
                  >
                    {TEST_CARD_PROFILES.map(profile => (
                      <option key={profile.key} value={profile.key}>{profile.label}</option>
                    ))}
                  </select>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                  <div className="mb-3 flex items-center justify-between">
                    <div>
                      <div className="text-xs font-black text-slate-700">合成客户资料</div>
                      <div className="text-[10px] text-slate-400">用于支付安全演示，不对应真实客户。</div>
                    </div>
                    <button type="button" onClick={loadCustomerProfile} disabled={workflow !== "IDLE"} className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-bold text-slate-600 disabled:opacity-40">
                      随机客户
                    </button>
                  </div>

                  <div className="grid gap-3 md:grid-cols-2">
                    <label className="block">
                      <span className="mb-1 block text-[11px] font-bold text-slate-500">姓名</span>
                      <input
                        value={form.fullName}
                        onBlur={() => touchField("fullName")}
                        onChange={e => updateField("fullName", e.target.value)}
                        disabled={workflow !== "IDLE"}
                        className={`w-full rounded-xl border p-3 text-sm outline-none focus:ring-4 disabled:bg-slate-100 ${fieldErrors.fullName ? "border-rose-300 focus:ring-rose-100" : "border-slate-200 focus:border-sky-400 focus:ring-sky-100"}`}
                      />
                      {fieldErrors.fullName && <span className="mt-1 block text-[10px] text-rose-600">{fieldErrors.fullName}</span>}
                    </label>

                    <label className="block">
                      <span className="mb-1 block text-[11px] font-bold text-slate-500">电话</span>
                      <input
                        value={form.phone}
                        onBlur={() => touchField("phone")}
                        onChange={e => updateField("phone", e.target.value)}
                        disabled={workflow !== "IDLE"}
                        className={`w-full rounded-xl border p-3 text-sm outline-none focus:ring-4 disabled:bg-slate-100 ${fieldErrors.phone ? "border-rose-300 focus:ring-rose-100" : "border-slate-200 focus:border-sky-400 focus:ring-sky-100"}`}
                      />
                      {fieldErrors.phone && <span className="mt-1 block text-[10px] text-rose-600">{fieldErrors.phone}</span>}
                    </label>
                  </div>

                  <label className="mt-3 block">
                    <span className="mb-1 block text-[11px] font-bold text-slate-500">地址</span>
                    <input
                      value={form.address}
                      onBlur={() => touchField("address")}
                      onChange={e => updateField("address", e.target.value)}
                      disabled={workflow !== "IDLE"}
                      className={`w-full rounded-xl border p-3 text-sm outline-none focus:ring-4 disabled:bg-slate-100 ${fieldErrors.address ? "border-rose-300 focus:ring-rose-100" : "border-slate-200 focus:border-sky-400 focus:ring-sky-100"}`}
                    />
                    {fieldErrors.address && <span className="mt-1 block text-[10px] text-rose-600">{fieldErrors.address}</span>}
                  </label>
                </div>

                <div className="grid gap-3 md:grid-cols-[1fr_120px_110px]">
                  <label className="block">
                    <span className="mb-1 flex items-center justify-between text-[11px] font-bold text-slate-500">
                      <span>{openMode ? "卡号" : "TEST 卡号"}</span>
                      <span>{brand} · {cardClass}/{fundingType}</span>
                    </span>
                    <input
                      value={form.cardNumber}
                      inputMode="numeric"
                      autoComplete="off"
                      onBlur={() => touchField("cardNumber")}
                      onChange={e => onCardChange(e.target.value)}
                      disabled={workflow !== "IDLE"}
                      className={`w-full rounded-xl border p-3 font-mono text-sm outline-none focus:ring-4 disabled:bg-slate-100 ${fieldErrors.cardNumber ? "border-rose-300 focus:ring-rose-100" : "border-slate-200 focus:border-sky-400 focus:ring-sky-100"}`}
                    />
                    {fieldErrors.cardNumber && <span className="mt-1 block text-[10px] text-rose-600">{fieldErrors.cardNumber}</span>}
                  </label>

                  <label className="block">
                    <span className="mb-1 block text-[11px] font-bold text-slate-500">有效期</span>
                    <input
                      value={form.expiryDate}
                      inputMode="numeric"
                      maxLength={5}
                      onBlur={() => touchField("expiryDate")}
                      onChange={e => updateField("expiryDate", formatExpiry(e.target.value))}
                      disabled={workflow !== "IDLE"}
                      className={`w-full rounded-xl border p-3 text-center font-mono text-sm outline-none focus:ring-4 disabled:bg-slate-100 ${fieldErrors.expiryDate ? "border-rose-300 focus:ring-rose-100" : "border-slate-200 focus:border-sky-400 focus:ring-sky-100"}`}
                    />
                    {fieldErrors.expiryDate && <span className="mt-1 block text-[10px] text-rose-600">{fieldErrors.expiryDate}</span>}
                  </label>

                  <label className="block">
                    <span className="mb-1 block text-[11px] font-bold text-slate-500">{openMode ? "CVV（3 或 4 位）" : "TEST CVV"}</span>
                    <input
                      value={form.cvv}
                      inputMode="numeric"
                      maxLength={4}
                      onBlur={() => touchField("cvv")}
                      onChange={e => updateField("cvv", e.target.value.replace(/\D/g, "").slice(0, expectedCvvLength))}
                      disabled={workflow !== "IDLE"}
                      className={`w-full rounded-xl border p-3 text-center font-mono text-sm outline-none focus:ring-4 disabled:bg-slate-100 ${fieldErrors.cvv ? "border-rose-300 focus:ring-rose-100" : "border-slate-200 focus:border-sky-400 focus:ring-sky-100"}`}
                    />
                    {fieldErrors.cvv && <span className="mt-1 block text-[10px] text-rose-600">{fieldErrors.cvv}</span>}
                  </label>
                </div>

                {errorMessage && (
                  <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-medium text-rose-700">{errorMessage}</div>
                )}

                <button
                  type="submit"
                  disabled={workflow !== "IDLE" || !online}
                  className="w-full rounded-xl bg-slate-950 py-3.5 text-xs font-black tracking-wide text-white shadow-lg transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
                >
                  {workflow === "SUBMITTING" ? "正在创建安全支付会话..." : "启动支付安全演示"}
                </button>
              </form>
            </div>

            <div className="space-y-5">
              <div className="relative aspect-[1.586/1] overflow-hidden rounded-[28px] border border-white/20 shadow-2xl">
                <img src={selectedProfile.background} alt="" className="absolute inset-0 h-full w-full object-cover" />
                <div className="absolute inset-0 bg-gradient-to-br from-white/10 via-transparent to-black/25" />
                <div className="absolute left-7 top-7 h-11 w-16 rounded-md bg-gradient-to-br from-yellow-100 via-amber-300 to-yellow-500 shadow" />
                <img src={selectedProfile.logo} alt="" className="absolute right-7 top-7 h-14 max-w-[150px] object-contain" />
                <div className="absolute left-7 top-24 text-[10px] font-bold uppercase tracking-[0.22em] text-white/80">
                  {classLabel(cardClass)} · {fundingType}
                </div>
                <div className="absolute left-7 right-7 top-[50%] -translate-y-1/2 text-center font-mono text-[clamp(18px,3vw,28px)] font-semibold tracking-[0.13em] text-white drop-shadow-lg">
                  {form.cardNumber || "•••• •••• •••• ••••"}
                </div>
                <div className="absolute bottom-8 left-7">
                  <div className="text-[8px] uppercase tracking-[0.22em] text-white/60">Card Holder</div>
                  <div className="mt-1 max-w-[260px] truncate text-sm font-bold uppercase text-white">{form.fullName || "SANDBOX USER"}</div>
                </div>
                <div className="absolute bottom-8 right-7 text-right">
                  <div className="text-[8px] uppercase tracking-[0.22em] text-white/60">Expires</div>
                  <div className="mt-1 font-mono text-sm font-bold text-white">{form.expiryDate || "MM/YY"}</div>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Card Profile</div>
                  <div className="mt-2 text-sm font-black text-slate-800">{cardClass} · {fundingType}</div>
                  <div className="mt-1 text-[11px] text-slate-500">{brand} · {classLabel(cardClass)}</div>
                </div>
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Risk Radar</div>
                  <div className={`mt-2 text-sm font-black ${riskScore >= 60 ? "text-rose-600" : riskScore >= 30 ? "text-amber-600" : "text-emerald-600"}`}>RISK {riskScore}</div>
                  <div className="mt-1 text-[11px] text-slate-500">设备与网络安全演示评分</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {show3ds && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-md">
            <div role="dialog" aria-modal="true" aria-label="3DS Sandbox" className="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl">
              <div className="bg-slate-950 p-5 text-white">
                <div className="flex items-center justify-between gap-4">
                  <div>
                    <div className="text-[10px] font-black uppercase tracking-widest text-amber-300">3DS Sandbox · {brand}</div>
                    <h3 className="mt-1 font-black">模拟发卡行安全验证</h3>
                  </div>
                  <div className={`rounded-lg px-2 py-1 text-[10px] font-black ${riskScore >= 60 ? "bg-rose-500/20 text-rose-300" : "bg-emerald-500/20 text-emerald-300"}`}>RISK {riskScore}</div>
                </div>
              </div>

              <div className="space-y-4 p-6">
                <div className="rounded-2xl bg-slate-50 p-4 text-xs text-slate-600">
                  <div className="flex items-center justify-between gap-4">
                    <span>Tx ID</span><strong className="font-mono text-[11px]">{orderId}</strong>
                  </div>
                  <div className="mt-2 flex items-center justify-between gap-4">
                    <span>Card</span><strong className="font-mono">{form.cardNumber}</strong>
                  </div>
                  <div className="mt-2 flex items-center justify-between gap-4">
                    <span>Class</span><strong>{cardClass} · {fundingType}</strong>
                  </div>
                </div>

                {(workflow === "OTP_REQUIRED" || workflow === "VERIFYING") && (
                  <>
                    <div className="flex items-center justify-between">
                      <div>
                        <div className="text-xs font-black text-slate-800">输入 TEST OTP</div>
                        <div className="mt-1 text-[10px] text-slate-400">支持任意 4 位或 6 位数字，由后台人工决定后续状态。</div>
                      </div>
                      <div className={`font-mono text-lg font-black ${countdown <= 10 ? "text-rose-600" : "text-slate-700"}`}>{countdown}s</div>
                    </div>

                    <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                      <div className={`h-full transition-all duration-1000 ${countdown <= 10 ? "bg-rose-500" : "bg-sky-500"}`} style={{ width: `${(countdown / OTP_SECONDS) * 100}%` }} />
                    </div>

                    {otpFeedback && (
                      <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-bold text-amber-800">{otpFeedback}</div>
                    )}

                    <input
                      ref={otpInputRef}
                      value={sandboxCode}
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      aria-label="TEST OTP"
                      onChange={e => setSandboxCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
                      onKeyDown={e => {
                        if (e.key === "Enter" && isValidOtp(sandboxCode)) {
                          e.preventDefault();
                          submitOtp();
                        }
                      }}
                      className="w-full rounded-2xl border border-slate-200 p-4 text-center font-mono text-2xl font-black tracking-[0.32em] outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100"
                      placeholder="••••••"
                      maxLength={6}
                    />

                    <button
                      type="button"
                      onClick={submitOtp}
                      disabled={!isValidOtp(sandboxCode) || workflow === "VERIFYING" || !online}
                      className="w-full rounded-xl bg-slate-950 py-3.5 text-xs font-black text-white transition disabled:opacity-40"
                    >
                      {workflow === "VERIFYING" ? "正在提交到反欺诈中枢..." : "提交 TEST OTP"}
                    </button>
                    <button
                      type="button"
                      onClick={cancelTransaction}
                      disabled={workflow === "VERIFYING"}
                      className="w-full rounded-xl border border-slate-200 py-3 text-xs font-bold text-slate-600 transition hover:bg-slate-50 disabled:opacity-40"
                    >
                      取消验证并返回结账页
                    </button>
                  </>
                )}

                {workflow === "WAITING_REVIEW" && (
                  <div className="py-8 text-center">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-sky-50 text-2xl">📡</div>
                    <div className="mt-4 font-black text-slate-800">已进入后台人工审核</div>
                    <div className="mt-2 text-xs text-slate-500">后台可选择“放行”或“拒绝并要求重新输入 OTP”。</div>
                    <div className={`mx-auto mt-4 inline-flex rounded-full px-3 py-1 text-[10px] font-bold ${pollStatus === "retry" ? "bg-amber-100 text-amber-700" : "bg-emerald-100 text-emerald-700"}`}>
                      {pollStatus === "retry" ? "状态同步重试中" : "实时状态同步中"}
                    </div>
                  </div>
                )}

                {workflow === "CARD_UNSUPPORTED" && (
                  <div className="py-7 text-center">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-rose-50 text-2xl">💳</div>
                    <div className="mt-4 text-lg font-black text-rose-700">当前卡不支持付款</div>
                    <div className="mt-2 text-xs leading-5 text-slate-500">该测试卡当前不支持付款，请更换卡后重新尝试。</div>
                    <button type="button" onClick={switchToNextCard} className="mt-5 w-full rounded-xl bg-slate-950 py-3.5 text-xs font-black text-white">
                      更换下一张 TEST 卡
                    </button>
                    <button type="button" onClick={() => resetTransaction(true)} className="mt-2 w-full rounded-xl border border-slate-200 py-3 text-xs font-bold text-slate-600">
                      返回支付页
                    </button>
                  </div>
                )}

                {workflow === "APPROVED" && (
                  <div className="py-7 text-center">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-2xl">✓</div>
                    <div className="mt-4 text-lg font-black text-emerald-700">TEST 支付已通过</div>
                    <div className="mt-2 text-xs leading-5 text-slate-500">后台已放行本次验证；这是本地 TEST 沙盒结果，不会发起真实扣款。</div>
                    <button type="button" onClick={() => resetTransaction(true)} className="mt-5 w-full rounded-xl bg-slate-950 py-3.5 text-xs font-black text-white">
                      返回支付页
                    </button>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}
      </section>
    );
  },
};
