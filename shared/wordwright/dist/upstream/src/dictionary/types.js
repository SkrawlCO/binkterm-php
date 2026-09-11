/** Raised when a dictionary chunk cannot be loaded, so the UI can offer Retry (EC-14). */
export class DictionaryLoadError extends Error {
    wordLength;
    constructor(wordLength, cause) {
        super(`Failed to load the ${wordLength}-letter word list`);
        this.name = 'DictionaryLoadError';
        this.wordLength = wordLength;
        if (cause !== undefined)
            this.cause = cause;
    }
}
