FROM node:22-alpine

WORKDIR /app

# Dependencies are installed at container start (see command below) rather
# than baked into the image, so `npm install` always reflects the bind-mounted
# package.json without a rebuild — matches the "minimal manual setup" goal
# for local dev. A production image would COPY + npm ci + vite build instead.
CMD ["sh", "-c", "npm install && npm run dev -- --host 0.0.0.0"]
