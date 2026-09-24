FROM python:3.12-slim
WORKDIR /app
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
COPY app/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt
COPY app /app
RUN useradd -r -u 10001 -g root dpm && chown -R dpm:root /app
EXPOSE 8787
CMD ["uvicorn","main:app","--host","0.0.0.0","--port","8787"]
