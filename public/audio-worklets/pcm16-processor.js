class Pcm16Processor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.inputSamples = [];
        this.readPosition = 0;
        this.outputSamples = [];
        this.targetSampleRate = 24000;
        this.frameSize = 1920;
    }

    process(inputs) {
        const channel = inputs[0]?.[0];
        if (!channel) return true;

        for (const sample of channel) this.inputSamples.push(sample);

        const ratio = sampleRate / this.targetSampleRate;
        while (this.readPosition + 1 < this.inputSamples.length) {
            const index = Math.floor(this.readPosition);
            const fraction = this.readPosition - index;
            const sample = this.inputSamples[index] * (1 - fraction) + this.inputSamples[index + 1] * fraction;
            this.outputSamples.push(Math.max(-1, Math.min(1, sample)));
            this.readPosition += ratio;
        }

        const consumed = Math.floor(this.readPosition);
        if (consumed > 0) {
            this.inputSamples.splice(0, consumed);
            this.readPosition -= consumed;
        }

        while (this.outputSamples.length >= this.frameSize) {
            const frame = this.outputSamples.splice(0, this.frameSize);
            const pcm = new Int16Array(frame.length);
            let energy = 0;
            for (let index = 0; index < frame.length; index++) {
                const sample = frame[index];
                energy += sample * sample;
                pcm[index] = sample < 0 ? sample * 0x8000 : sample * 0x7fff;
            }
            this.port.postMessage(
                { pcm: pcm.buffer, level: Math.sqrt(energy / frame.length) },
                [pcm.buffer],
            );
        }

        return true;
    }
}

registerProcessor('pcm16-processor', Pcm16Processor);
