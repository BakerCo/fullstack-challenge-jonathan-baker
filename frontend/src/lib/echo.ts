export type WeatherUpdatedPayload = {
  lat: number;
  lon: number;
  weather: any | null;
  error: { code?: number | null; message?: string | null } | null;
};

let echoInstance: any | null = null;

async function ensureEcho() {
  if (echoInstance) return echoInstance;
  try {
    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
      import("laravel-echo"),
      import("pusher-js"),
    ]);
    (window as any).Pusher = Pusher;

    const key = (import.meta as any).env.VITE_REVERB_APP_KEY;
    const host = (import.meta as any).env.VITE_REVERB_HOST || "localhost";
    const portRaw = (import.meta as any).env.VITE_REVERB_PORT || "8080";
    const useTLS =
      ((import.meta as any).env.VITE_REVERB_SCHEME || "http") === "https";

    const port = Number(portRaw);

    echoInstance = new Echo({
      broadcaster: "reverb",
      key,
      wsHost: host,
      wsPort: port,
      wssPort: port,
      forceTLS: useTLS,
      enabledTransports: ["ws", "wss"],
    });

    return echoInstance;
  } catch (e) {
    console.warn("[Echo] Failed to initialize Echo (reverb):", e);
    return null;
  }
}

export async function subscribeWeather(
  cb: (payload: WeatherUpdatedPayload) => void
) {
  const echo = await ensureEcho();
  if (!echo) {
    console.warn("[Echo] Not available; skipping subscription");
    return () => {};
  }

  const channel = echo.channel("weather");
  const handler = (payload: WeatherUpdatedPayload) => cb(payload);

  channel.listen(".WeatherUpdated", handler);

  return () => {
    try {
      channel.stopListening(".WeatherUpdated", handler);
      echo.leave("weather");
    } catch {
      console.warn("[Echo] Failed to unsubscribe from weather updates");
    }
  };
}
