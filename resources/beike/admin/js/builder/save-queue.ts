/** All writes, including imports and restores, share one ordered boundary. */
export class BuilderWriteQueue {
  private tail: Promise<unknown> = Promise.resolve();

  public run<T>(write: () => Promise<T>): Promise<T> {
    const result = this.tail.then(write);
    // A rejected write must not prevent the user from retrying.
    this.tail = result.catch(() => undefined);
    return result;
  }
}
