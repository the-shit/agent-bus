<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    stream: { type: String, default: 'AGENT_BUS' },
    consumer: { type: String, default: '' },
});

const TYPES = {
    sessionStart: { label: 'session start', classes: 'bg-emerald-500/15 text-emerald-400 ring-emerald-500/30' },
    sessionEnd: { label: 'session end', classes: 'bg-rose-500/15 text-rose-400 ring-rose-500/30' },
    idle: { label: 'idle', classes: 'bg-sky-500/15 text-sky-400 ring-sky-500/30' },
    toolCall: { label: 'tool call', classes: 'bg-violet-500/15 text-violet-400 ring-violet-500/30' },
    errorRaised: { label: 'error', classes: 'bg-red-500/15 text-red-400 ring-red-500/30' },
};

const MAX = 300;

const envelopes = ref([]);
const wsState = ref('connecting');
const paused = ref(false);
const typeFilter = ref('*');
const agentFilter = ref('*');

const agents = computed(() =>
    [...new Set(envelopes.value.map((e) => e.agentType).filter(Boolean))].sort(),
);

const visible = computed(() =>
    envelopes.value.filter((e) => {
        if (typeFilter.value !== '*' && e.type !== typeFilter.value) return false;
        if (agentFilter.value !== '*' && e.agentType !== agentFilter.value) return false;
        return true;
    }),
);

const statusClasses = {
    connected: 'bg-emerald-500',
    connecting: 'bg-amber-400 animate-pulse',
    disconnected: 'bg-gray-500',
    error: 'bg-red-500',
};

function push(envelope) {
    if (!envelope || typeof envelope !== 'object') return;
    envelopes.value.unshift({ ...envelope, receivedAt: Date.now() });
    if (envelopes.value.length > MAX) envelopes.value.pop();
}

function short(value) {
    return value && typeof value === 'string' && value.length > 12 ? value.slice(0, 12) : value ?? '—';
}

function badgeFor(type) {
    return TYPES[type] ?? { label: type ?? 'unknown', classes: 'bg-gray-500/15 text-gray-300 ring-gray-500/30' };
}

function payloadFor(envelope) {
    if (envelope.payload === undefined || envelope.payload === null) return '';
    const json = JSON.stringify(envelope.payload);
    return json.length > 220 ? `${json.slice(0, 220)}…` : json;
}

function timeFor(timestamp, receivedAt) {
    return new Date(timestamp ?? receivedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function clear() {
    envelopes.value = [];
}

onMounted(() => {
    const connection = window.Echo.connector.pusher.connection;
    connection.bind('connecting', () => (wsState.value = 'connecting'));
    connection.bind('connected', () => (wsState.value = 'connected'));
    connection.bind('disconnected', () => (wsState.value = 'disconnected'));
    connection.bind('error', () => (wsState.value = 'error'));
    wsState.value = connection.state;

    window.Echo
        .channel('bus')
        .listen('BusEnvelopeBroadcast', (envelope) => {
            if (!paused.value) push(envelope);
        });
});

onBeforeUnmount(() => window.Echo.leaveChannel('bus'));
</script>

<template>
    <div class="min-h-screen bg-zinc-950 text-zinc-200">
        <header class="sticky top-0 z-10 border-b border-zinc-800 bg-zinc-950/90 backdrop-blur">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-2.5 rounded-full" :class="statusClasses[wsState]"></div>
                    <h1 class="text-sm font-semibold tracking-tight text-zinc-100">Agent Bus</h1>
                    <p class="hidden text-xs text-zinc-500 sm:block">{{ props.stream }} · {{ props.consumer }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-xs font-medium text-zinc-400 ring-1 ring-zinc-700 transition hover:text-zinc-100 hover:ring-zinc-500"
                        @click="paused = !paused"
                    >
                        {{ paused ? 'resume' : 'pause' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-xs font-medium text-zinc-400 ring-1 ring-zinc-700 transition hover:text-zinc-100 hover:ring-zinc-500"
                        @click="clear"
                    >
                        clear
                    </button>
                </div>
            </div>

            <div class="mx-auto flex flex-wrap items-center gap-2 px-6 pb-3">
                <button
                    v-for="(meta, type) in TYPES"
                    :key="type"
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-medium ring-1 transition"
                    :class="typeFilter === type ? meta.classes : 'text-zinc-500 ring-zinc-800 hover:text-zinc-300'"
                    @click="typeFilter = typeFilter === type ? '*' : type"
                >
                    {{ meta.label }}
                </button>

                <span v-if="agents.length > 1" class="mx-1 h-4 w-px bg-zinc-800"></span>

                <button
                    v-for="agent in agents"
                    :key="agent"
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-medium ring-1 transition"
                    :class="agentFilter === agent ? 'bg-zinc-200/15 text-zinc-100 ring-zinc-500' : 'text-zinc-500 ring-zinc-800 hover:text-zinc-300'"
                    @click="agentFilter = agentFilter === agent ? '*' : agent"
                >
                    {{ agent }}
                </button>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-6">
            <p v-if="visible.length === 0" class="py-16 text-center text-sm text-zinc-600">
                {{ wsState === 'connected' ? 'No envelopes yet — make an agent do something.' : 'Waiting for the bus…' }}
            </p>

            <ol class="space-y-2">
                <li
                    v-for="envelope in visible"
                    :key="`${envelope.receivedAt}-${envelope.sessionId}-${envelope.timestamp}`"
                    class="flex items-start gap-3 rounded-xl border border-zinc-800/80 bg-zinc-900/50 px-4 py-3"
                >
                    <span
                        class="mt-0.5 shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1"
                        :class="badgeFor(envelope.type).classes"
                    >
                        {{ badgeFor(envelope.type).label }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-xs">
                            <span class="font-mono font-medium text-zinc-100">{{ envelope.agentType }}</span>
                            <span v-if="envelope.model" class="font-mono text-zinc-500">{{ envelope.model }}</span>
                            <span class="font-mono text-zinc-500">{{ short(envelope.sessionId) }}</span>
                            <span v-if="envelope.repo" class="font-mono text-zinc-500">{{ envelope.repo }}</span>
                        </div>
                        <p v-if="payloadFor(envelope)" class="mt-1 font-mono text-[11px] leading-relaxed text-zinc-500">
                            {{ payloadFor(envelope) }}
                        </p>
                    </div>

                    <time class="shrink-0 font-mono text-[11px] text-zinc-600">{{ timeFor(envelope.timestamp, envelope.receivedAt) }}</time>
                </li>
            </ol>
        </main>
    </div>
</template>