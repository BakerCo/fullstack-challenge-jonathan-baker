<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from "vue";
import { subscribeWeather, type WeatherUpdatedPayload } from "@/lib/echo";

/*
    Ideally I would split this up and handle things in a more modular way,
    but for the sake of time and my marriage 🥴, I wrapped it up as you see below
*/

type User = {
  id: number;
  name: string;
  email: string;
  latitude: number;
  longitude: number;
};

type Weather = {
  tempC: number;
  tempF: number;
  condition: string;
  iconUrl: string | null;
  windKph: number;
  humidity: number;
  feelsLikeC: number;
  feelsLikeF: number;
  source: string;
  observedAt: string; // ISO string
};

type WeatherError = {
  code?: number | null;
  message?: string | null;
} | null;

type UserWeatherItem = {
  user: User;
  weather: Weather | null;
  error: WeatherError;
};

const API_BASE =
  (import.meta.env as any).VITE_API_BASE_URL || "http://localhost";

const loading = ref(false);
const errorMessage = ref<string | null>(null);
const items = ref<UserWeatherItem[]>([]);

const selected = ref<User | null>(null);
const detailsLoading = ref(false);
const details = ref<UserWeatherItem | null>(null);
const showModal = ref(false);

function formatDate(iso?: string) {
  if (!iso) return "";
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

async function fetchUsers() {
  loading.value = true;
  errorMessage.value = null;
  try {
    const res = await fetch(`${API_BASE}/users`, {
      headers: { Accept: "application/json" },
    });

    if (!res.ok) {
      throw new Error(`Failed to load users (${res.status})`);
    }

    // Laravel resource collection returns an array at the top level in this setup
    const data = await res.json();
    items.value = Array.isArray(data) ? data : data?.data ?? [];
  } catch (err: any) {
    errorMessage.value = err?.message ?? "Failed to load";
  } finally {
    loading.value = false;
  }
}

async function openDetails(user: User) {
  selected.value = user;
  details.value = null;
  showModal.value = true;
  detailsLoading.value = true;
  try {
    const res = await fetch(`${API_BASE}/users/${user.id}/weather`, {
      headers: { Accept: "application/json" },
    });

    if (!res.ok) {
      throw new Error(`Failed to load user (${res.status})`);
    }

    const { data } = await res.json();
    details.value = data as UserWeatherItem;
  } catch (err: any) {
    details.value = {
      user,
      weather: null,
      error: { message: err?.message ?? "Failed to load" },
    };
  } finally {
    detailsLoading.value = false;
  }
}

function closeModal() {
  showModal.value = false;
}

function tempSummary(w: Weather | null) {
  if (!w) return "–";
  return `${w.tempC.toFixed(1)}°C / ${w.tempF.toFixed(1)}°F`;
}

let unsubscribe: null | (() => void) = null;

onMounted(async () => {
  await fetchUsers();
  // Subscribe to weather updates and merge into the list
  unsubscribe = await subscribeWeather((p: WeatherUpdatedPayload) => {
    // Find any user with matching lat/lon and merge the weather data
    const idx = items.value.findIndex(
      (it) =>
        Math.abs(it.user.latitude - p.lat) < 1e-6 &&
        Math.abs(it.user.longitude - p.lon) < 1e-6
    );
    if (idx >= 0) {
      const it = items.value[idx];
      const updated: UserWeatherItem = {
        user: it.user,
        weather: p.weather as any,
        error: p.error ?? null,
      };
      // Replace the item to trigger reactivity
      items.value = [
        ...items.value.slice(0, idx),
        updated,
        ...items.value.slice(idx + 1),
      ];

      // If the modal is open for this user, update details too
      if (selected.value && selected.value.id === it.user.id && details.value) {
        details.value = updated;
      }
    }
  });
});

onBeforeUnmount(() => {
  if (unsubscribe) unsubscribe();
});
</script>

<template>
  <main class="container">
    <section class="header">
      <h1>Users Weather</h1>
      <div class="actions">
        <button class="btn" :disabled="loading" @click="fetchUsers">
          {{ loading ? "Loading…" : "Refresh" }}
        </button>
      </div>
    </section>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

    <div v-if="loading && !items.length" class="loading">Loading users…</div>

    <div v-else class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Weather</th>
            <th>Condition</th>
            <th>Observed</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="it in items" :key="it.user.id">
            <td>{{ it.user.name }}</td>
            <td>{{ it.user.email }}</td>
            <td>
              <span v-if="it.weather">{{ tempSummary(it.weather) }}</span>
              <span v-else class="muted">N/A</span>
            </td>
            <td>
              <span v-if="it.weather">{{ it.weather.condition }}</span>
              <span
                v-else
                class="error"
                v-text="it.error?.message || 'Unavailable'"
              ></span>
            </td>
            <td>
              <span v-if="it.weather">{{
                formatDate(it.weather.observedAt)
              }}</span>
              <span v-else class="muted">—</span>
            </td>
            <td>
              <button class="btn" @click="openDetails(it.user)">Details</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="modal-backdrop" @click.self="closeModal">
      <div class="modal">
        <header class="modal-header">
          <h2>User Weather</h2>
          <button class="btn btn-ghost" @click="closeModal">✕</button>
        </header>
        <section class="modal-body" v-if="selected">
          <div class="user-meta">
            <div class="row">
              <strong>Name:</strong> <span>{{ selected.name }}</span>
            </div>
            <div class="row">
              <strong>Email:</strong> <span>{{ selected.email }}</span>
            </div>
            <div class="row">
              <strong>Coords:</strong>
              <span>{{ selected.latitude }}, {{ selected.longitude }}</span>
            </div>
          </div>

          <div v-if="detailsLoading" class="loading">Loading weather…</div>

          <div v-else-if="details">
            <template v-if="details.weather">
              <div class="weather">
                <div class="row">
                  <strong>Temperature:</strong>
                  <span>{{ tempSummary(details.weather) }}</span>
                </div>
                <div class="row">
                  <strong>Condition:</strong>
                  <span class="cond">
                    <img
                      v-if="details.weather.iconUrl"
                      :src="details.weather.iconUrl"
                      alt="icon"
                      class="icon"
                    />
                    {{ details.weather.condition }}
                  </span>
                </div>
                <div class="row">
                  <strong>Feels Like:</strong>
                  <span
                    >{{ details.weather.feelsLikeC.toFixed(1) }}°C /
                    {{ details.weather.feelsLikeF.toFixed(1) }}°F</span
                  >
                </div>
                <div class="row">
                  <strong>Wind:</strong>
                  <span>{{ details.weather.windKph.toFixed(1) }} kph</span>
                </div>
                <div class="row">
                  <strong>Humidity:</strong>
                  <span>{{ details.weather.humidity }}%</span>
                </div>
                <div class="row">
                  <strong>Source:</strong>
                  <span>{{ details.weather.source }}</span>
                </div>
                <div class="row">
                  <strong>Observed:</strong>
                  <span>{{ formatDate(details.weather.observedAt) }}</span>
                </div>
              </div>
            </template>
            <template v-else>
              <p class="error">
                {{ details.error?.message || "Weather unavailable" }}
              </p>
            </template>
          </div>
        </section>
      </div>
    </div>
  </main>
</template>

<style scoped>
.container {
  max-width: 960px;
  margin: 0 auto;
  padding: 1rem;
}
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}
.btn {
  background: #2f74c0;
  color: white;
  padding: 0.5rem 0.75rem;
  border-radius: 6px;
  border: 0;
  cursor: pointer;
}
.btn[disabled] {
  opacity: 0.6;
  cursor: default;
}
.btn-ghost {
  background: transparent;
  color: #333;
  padding: 0.25rem 0.5rem;
}
.table-wrap {
  overflow-x: auto;
}
.table {
  width: 100%;
  border-collapse: collapse;
}
.table th,
.table td {
  padding: 0.5rem;
  border-bottom: 1px solid #eee;
  text-align: left;
}
.loading {
  color: #555;
}
.error {
  color: #c0392b;
}
.muted {
  color: #888;
}
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.modal {
  width: 100%;
  max-width: 560px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #eee;
}
.modal-body {
  padding: 1rem;
}
.row {
  display: flex;
  gap: 0.5rem;
  margin: 0.3rem 0;
  align-items: center;
}
.cond {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}
.icon {
  width: 28px;
  height: 28px;
  object-fit: contain;
}
</style>
