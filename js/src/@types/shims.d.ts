/**
 * Augmentações mínimas de tipo para a extensão MyBB Migrator.
 */

declare global {
  /**
   * Webpack injeta `process.env.NODE_ENV` via DefinePlugin. Sem `@types/node`,
   * declaramos só o subset usado.
   */
  const process: {
    env: {
      NODE_ENV?: string;
      [key: string]: string | undefined;
    };
  };
}

export {};
